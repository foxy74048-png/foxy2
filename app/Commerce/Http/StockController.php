<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockMovement;
use App\Commerce\Inventory\StockService;
use App\Models\User;
use App\Platform\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Estoque da loja (docs/09, fatia 4): lista com disponível (kits calculados pelas peças), entrada de
 * mercadoria em lote, ajuste por variante e histórico.
 */
class StockController
{
    public function __construct(private StockService $stock) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'filtro' => ['nullable', Rule::in(['baixo', 'zerado', 'negativo'])],
            'aba' => ['nullable', Rule::in(['posicao', 'entrada'])],
        ]);

        if (($filters['aba'] ?? null) === 'entrada' && Access::allows($request->attributes->get('commerce.membership'), 'commerce.stock.adjust')) {
            return $this->receiveForm();
        }

        $variants = Variant::with('product')
            ->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))
            ->when($filters['busca'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->whereLike('sku', $like)->orWhereHas('product', fn ($p) => $p->whereLike('name', $like)));
            })
            ->orderBy('sku')->limit(2000)->get();

        $available = $this->stock->availability($variants);
        $levels = StockLevel::whereIn('variant_id', $variants->pluck('id'))->get()->keyBy('variant_id');

        $rows = $variants->map(fn (Variant $v) => [
            'variant' => $v,
            'level' => $levels->get($v->id),
            'available' => $available[$v->id],
            'status' => self::status($v, $levels->get($v->id), $available[$v->id]),
        ]);

        $counts = $rows->countBy('status');
        $rows = match ($filters['filtro'] ?? null) {
            'baixo' => $rows->whereIn('status', ['baixo', 'zerado', 'negativo']),
            'zerado' => $rows->whereIn('status', ['zerado', 'negativo']),
            'negativo' => $rows->where('status', 'negativo'),
            default => $rows,
        };

        return view('commerce.stock.index', ['rows' => $rows->values(), 'filters' => $filters, 'counts' => $counts]);
    }

    /** Aba "Entrada de mercadoria": catálogo para achar o SKU enquanto digita e as últimas entradas. */
    private function receiveForm(): View
    {
        $variants = Variant::with('product')->where('status', 'active')->where('is_kit', false)
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('sku')->limit(5000)->get();
        $levels = StockLevel::whereIn('variant_id', $variants->pluck('id'))->pluck('on_hand', 'variant_id');

        $catalog = $variants->mapWithKeys(fn (Variant $v) => [$v->sku => ['name' => $v->fullName(), 'on_hand' => (int) ($levels[$v->id] ?? 0)]]);
        $recent = StockMovement::with(['variant.product', 'user'])->where('type', 'entrada')->latest()->latest('id')->limit(15)->get();

        return view('commerce.stock.receive', ['catalog' => $catalog, 'recent' => $recent]);
    }

    public function show(Variant $variant): View
    {
        $variant->load('product', 'components.component');
        $movements = StockMovement::where('variant_id', $variant->id)->latest('created_at')->latest('id')->paginate(50);
        $users = User::whereIn('id', $movements->pluck('user_id')->filter()->unique())->pluck('name', 'id');

        return view('commerce.stock.show', [
            'variant' => $variant,
            'level' => StockLevel::where('variant_id', $variant->id)->first(),
            'available' => $this->stock->availability(collect([$variant]))[$variant->id],
            'movements' => $movements,
            'users' => $users,
        ]);
    }

    /** Entrada de mercadoria: várias linhas de SKU + quantidade de uma vez (nota do fornecedor). */
    public function receive(Request $request): RedirectResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'max:200'],
            'items.*.sku' => ['nullable', 'string', 'max:55'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ], [], ['reason' => 'observação']);

        $items = collect($request->input('items'))->filter(fn ($i) => filled($i['sku'] ?? null));
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Informe pelo menos um SKU com quantidade.']);
        }

        $variants = Variant::whereIn('sku', $items->map(fn ($i) => Str::upper(trim($i['sku']))))->get()->keyBy('sku');
        foreach ($items as $item) {
            $sku = Str::upper(trim($item['sku']));
            if (! $variants->has($sku)) {
                throw ValidationException::withMessages(['items' => "Não achamos o SKU {$sku} nesta loja."]);
            }
            if (empty($item['quantity'])) {
                throw ValidationException::withMessages(['items' => "Falta a quantidade de {$sku}."]);
            }
        }

        DB::transaction(function () use ($items, $variants, $request) {
            foreach ($items as $item) {
                $this->stock->receive($variants->get(Str::upper(trim($item['sku']))), (int) $item['quantity'], $request->input('reason'));
            }
        });
        Audit::record('commerce.stock.received', metadata: ['items' => $items->count()]);

        return back()->with('status', 'Entrada registrada: '.$items->sum('quantity').' unidade(s) em '.$items->count().' SKU(s).');
    }

    /** Ajuste de uma variante: entrada, saída ou contagem. */
    public function adjust(Request $request, Variant $variant): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['entrada', 'saida', 'ajuste'])],
            'quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [], ['quantity' => 'quantidade', 'reason' => 'motivo']);

        match ($data['type']) {
            'entrada' => $this->stock->receive($variant, $data['quantity'], $data['reason'] ?? null),
            'saida' => $this->stock->remove($variant, $data['quantity'], $data['reason'] ?? null),
            'ajuste' => $this->stock->count($variant, $data['quantity'], $data['reason'] ?? null),
        };
        Audit::record('commerce.stock.'.$data['type'], subject: $variant, metadata: ['quantity' => $data['quantity']]);

        return back()->with('status', 'Estoque atualizado.');
    }

    public function minimum(Request $request, Variant $variant): RedirectResponse
    {
        $data = $request->validate(['min_stock' => ['nullable', 'integer', 'min:0', 'max:1000000']], [], ['min_stock' => 'estoque mínimo']);
        $this->stock->setMinimum($variant, $data['min_stock'] ?? null);

        return back()->with('status', 'Estoque mínimo salvo.');
    }

    /** negativo, zerado, baixo (no mínimo ou abaixo) ou ok. Kit não tem mínimo próprio. */
    public static function status(Variant $variant, ?StockLevel $level, int $available): string
    {
        return match (true) {
            ! $variant->is_kit && ($level?->on_hand ?? 0) < 0 => 'negativo',
            $available <= 0 => 'zerado',
            $level?->min_stock !== null && $available <= $level->min_stock => 'baixo',
            default => 'ok',
        };
    }
}
