<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Billing\Plans;
use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Product;
use App\Commerce\Insights\Export;
use App\Commerce\Inventory\StockService;
use App\Commerce\Supply\NfeParser;
use App\Commerce\Supply\ReceiptParser;
use App\Commerce\Supply\SupplierMemory;
use App\Commerce\Supply\SupplyBatch;
use App\Commerce\Supply\SupplyItem;
use App\Commerce\Supply\SupplyService;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remessas de fornecedor: importar o comprovante (PDF ou texto), conferir e lançar; acerto da consignação.
 */
class SupplyController
{
    public function __construct(private SupplyService $supply) {}

    public function index(Request $request): View|Response
    {
        $this->guard($request);
        $batches = SupplyBatch::withCount('items')->latest('received_on')->latest('created_at');
        if ($format = Export::requested($request)) {
            return (new Export('Remessas de fornecedor'))
                ->section(null, ['Recebida em' => 'date', 'Fornecedora' => 'text', 'Nº' => 'text', 'Fechamento' => 'date', 'Itens' => 'int', 'Total do comprovante' => 'money', 'Situação' => 'text'],
                    $batches->get()->map(fn (SupplyBatch $b) => [$b->received_on, $b->supplier, $b->document_number, $b->closing_on, $b->items_count, $b->total_cents, SupplyBatch::STATUSES[$b->status] ?? $b->status]))
                ->download($format);
        }

        return view('commerce.supply.index', ['batches' => $batches->paginate(30)]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'file' => ['nullable', 'file', 'extensions:pdf,txt,xml', 'max:10240'],
            'text' => ['nullable', 'string', 'max:500000'],
            'basis' => ['nullable', Rule::in(['auto', 'venda', 'custo'])],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
        ], [], ['file' => 'comprovante ou nota', 'basis' => 'preço do documento', 'percent' => 'percentual']);

        $file = $request->file('file');
        if ($file && ! in_array(strtolower($file->getClientOriginalExtension()), ['txt', 'xml'], true)) {
            $parsed = ReceiptParser::fromPdf($file->getRealPath()); // PDF: lido pela posição do texto na página
        } else {
            $text = $file ? (string) $file->get() : (string) ($data['text'] ?? '');
            $parsed = trim($text) === '' ? null : (NfeParser::looksLike($text) ? NfeParser::parse($text) : ReceiptParser::parse($text));
        }
        if ($parsed === null) {
            throw ValidationException::withMessages(['file' => 'Envie o PDF do comprovante, o XML da nota fiscal ou cole o texto do comprovante.']);
        }

        $basis = in_array($data['basis'] ?? null, ['venda', 'custo'], true) ? $data['basis'] : null;
        $batch = $this->supply->draft($parsed, $basis, isset($data['percent']) ? (float) $data['percent'] : null);

        return redirect()->route('commerce.supply.show', $batch)->with('status', ($parsed['source'] === 'nfe' ? 'Nota fiscal lida.' : 'Comprovante lido.').' Confira os produtos e clique em "Lançar no estoque".');
    }

    public function show(Request $request, SupplyBatch $batch): View|Response
    {
        $this->guard($request);
        $batch->load('items.variant.product', 'supplierRecord');
        if ($format = Export::requested($request)) {
            return (new Export('Remessa '.$batch->label()))
                ->filters(['Recebida em' => $batch->received_on?->format('d/m/Y'), 'Fechamento' => $batch->closing_on?->format('d/m/Y'), 'Situação' => SupplyBatch::STATUSES[$batch->status]])
                ->section(null, ['Descrição' => 'text', 'Código de barras' => 'text', 'Origem' => 'text', 'Qtd.' => 'int', 'Preço no comprovante' => 'money', 'Custo' => 'money', 'Devolvido' => 'int', 'Vendido' => 'int'],
                    $batch->items->map(fn (SupplyItem $i) => [$i->description, $i->barcode, $i->mode === 'compra' ? 'Comprado' : 'Consignado', $i->quantity, $i->document_price_cents, $i->cost_cents, $i->returned, $i->sold]))
                ->download($format);
        }

        $onHand = $batch->status === 'aberta'
            ? app(StockService::class)->availability($batch->items->pluck('variant')->filter())
            : [];

        return view('commerce.supply.show', [
            'batch' => $batch,
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'onHand' => $onHand,
            'knownSupplier' => $batch->supplierRecord && $batch->status === 'rascunho' && app(SupplierMemory::class)->known($batch->supplierRecord),
        ]);
    }

    /** Lança a remessa com o que foi ajustado na conferência. */
    public function confirm(Request $request, SupplyBatch $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'supplier' => ['required', 'string', 'max:120'],
            'document_number' => ['nullable', 'string', 'max:40'],
            'closing_on' => ['nullable', 'date'],
            'groups' => ['required', 'array'],
            'groups.*.name' => ['required', 'string', 'max:150'],
            'groups.*.mode' => ['required', Rule::in(array_keys(SupplyBatch::MODES))],
            'groups.*.category_id' => ['nullable', 'string', 'max:30'],
            'groups.*.sale' => ['required', 'string', 'max:20'],
            'groups.*.cost' => ['required', 'string', 'max:20'],
            'groups.*.skip' => ['nullable', 'boolean'],
            'groups.*.lines' => ['required', 'array'],
            'groups.*.lines.*.color' => ['nullable', 'string', 'max:40'],
            'groups.*.lines.*.size' => ['nullable', 'string', 'max:20'],
            'groups.*.lines.*.quantity' => ['required', 'integer', 'min:0', 'max:100000'],
            // Peças que a leitura não pegou, acrescentadas à mão: numa cor/tamanho de um produto lido ou num produto novo.
            'groups.*.extra' => ['nullable', 'array', 'max:200'],
            'groups.*.extra.*.color' => ['nullable', 'string', 'max:40'],
            'groups.*.extra.*.size' => ['nullable', 'string', 'max:20'],
            'groups.*.extra.*.barcode' => ['nullable', 'string', 'max:14'],
            'groups.*.extra.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'new_groups' => ['nullable', 'array', 'max:100'],
            'new_groups.*.name' => ['required', 'string', 'max:150'],
            'new_groups.*.mode' => ['required', Rule::in(array_keys(SupplyBatch::MODES))],
            'new_groups.*.category_id' => ['nullable', 'string', 'max:30'],
            'new_groups.*.sale' => ['required', 'string', 'max:20'],
            'new_groups.*.cost' => ['required', 'string', 'max:20'],
            'new_groups.*.lines' => ['required', 'array', 'max:200'],
            'new_groups.*.lines.*.color' => ['nullable', 'string', 'max:40'],
            'new_groups.*.lines.*.size' => ['nullable', 'string', 'max:20'],
            'new_groups.*.lines.*.barcode' => ['nullable', 'string', 'max:14'],
            'new_groups.*.lines.*.quantity' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ], [], ['supplier' => 'fornecedora', 'groups.*.name' => 'nome do produto', 'groups.*.sale' => 'preço de venda', 'groups.*.cost' => 'custo',
            'new_groups.*.name' => 'nome do produto acrescentado', 'new_groups.*.sale' => 'preço de venda', 'new_groups.*.cost' => 'custo']);
        $handLines = fn (string $name, array $rows) => collect($rows)->filter(fn ($r) => (int) ($r['quantity'] ?? 0) > 0)->map(function ($r) use ($name) {
            $color = trim($r['color'] ?? '');
            $size = mb_strtoupper(trim($r['size'] ?? ''));
            $barcode = preg_replace('/\D/', '', $r['barcode'] ?? '');

            return ['description' => Str::upper(trim("$name $color").($size !== '' ? " | $size" : '')), 'barcode' => strlen($barcode) >= 8 ? $barcode : null,
                'price_cents' => 0, 'color' => $color, 'size' => $size, 'quantity' => (int) $r['quantity'], 'manual' => true];
        })->values()->all();

        $draft = $batch->groups ?? [];
        $groups = [];
        foreach ($data['groups'] as $g => $input) {
            if (! isset($draft[$g])) {
                continue;
            }
            $lines = [];
            foreach ($draft[$g]['lines'] as $l => $line) {
                $edit = $input['lines'][$l] ?? [];
                if ((int) ($edit['quantity'] ?? $line['quantity']) <= 0) {
                    continue;
                }
                $lines[] = ['color' => trim($edit['color'] ?? $line['color']), 'size' => mb_strtoupper(trim($edit['size'] ?? $line['size'])),
                    'quantity' => (int) ($edit['quantity'] ?? $line['quantity'])] + $line;
            }
            array_push($lines, ...$handLines($input['name'], $input['extra'] ?? []));
            $sale = Money::toCents($input['sale']) ?? 0;
            if (! ($input['skip'] ?? false) && ($lines === [] || $sale <= 0)) {
                throw ValidationException::withMessages(["groups.$g.sale" => "Confira o preço de venda de \"{$input['name']}\"."]);
            }
            $groups[] = ['name' => trim($input['name']), 'mode' => $input['mode'], 'category_id' => Category::find($input['category_id'] ?? null)?->id,
                'sale_cents' => $sale, 'cost_cents' => Money::toCents($input['cost']) ?? 0, 'skip' => (bool) ($input['skip'] ?? false),
                'product_id' => $draft[$g]['product_id'] ?? null, 'lines' => $lines];
        }
        foreach ($data['new_groups'] ?? [] as $n => $input) {
            $lines = $handLines($input['name'], $input['lines']);
            $sale = Money::toCents($input['sale']) ?? 0;
            if ($lines === [] || $sale <= 0) {
                throw ValidationException::withMessages(["new_groups.$n.sale" => "Confira o preço de venda e a quantidade de \"{$input['name']}\"."]);
            }
            $groups[] = ['name' => trim($input['name']), 'mode' => $input['mode'], 'category_id' => Category::find($input['category_id'] ?? null)?->id,
                'sale_cents' => $sale, 'cost_cents' => Money::toCents($input['cost']) ?? 0, 'skip' => false, 'product_id' => null, 'lines' => $lines];
        }

        // Limite de produtos do plano vale também para a importação.
        $limit = Plans::limit(ExecutionContext::current()->organization, 'products');
        $new = collect($groups)->filter(fn ($g) => ! $g['skip'] && ! $g['product_id'])->count();
        if ($limit !== null && Product::where('status', '!=', 'archived')->count() + $new > $limit) {
            throw ValidationException::withMessages(['groups' => "Esta remessa criaria {$new} produto(s) novo(s) e o seu plano permite até {$limit}. Marque \"Não lançar\" em alguns ou mude de plano em Assinatura."]);
        }
        $batch->update(['supplier' => $data['supplier'], 'document_number' => $data['document_number'] ?? null, 'closing_on' => $data['closing_on'] ?? null]);
        $this->supply->confirm($batch, $groups);

        return redirect()->route('commerce.supply.show', $batch)->with('status', 'Remessa lançada: produtos cadastrados e estoque atualizado.'.($batch->items()->where('mode', 'compra')->exists() ? ' As peças compradas viraram conta a pagar no fechamento.' : ''));
    }

    public function settle(Request $request, SupplyBatch $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['returns' => ['nullable', 'array'], 'returns.*' => ['nullable', 'integer', 'min:0'], 'due' => ['nullable', 'date']]);
        $owed = $this->supply->settle($batch, array_map('intval', $data['returns'] ?? []), isset($data['due']) ? Carbon::parse($data['due']) : null);

        return back()->with('status', 'Acerto feito. As devoluções saíram do estoque'.($owed > 0 ? ' e o valor do vendido ('.Money::format($owed).') está em Financeiro › A pagar.' : '.'));
    }

    public function destroy(Request $request, SupplyBatch $batch): RedirectResponse
    {
        $this->guard($request);
        if ($batch->status !== 'rascunho') {
            throw ValidationException::withMessages(['status' => 'Só dá para descartar uma remessa que ainda não foi lançada.']);
        }
        $batch->delete();

        return redirect()->route('commerce.supply')->with('status', 'Leitura descartada.');
    }

    /** Remessa mexe em catálogo e estoque: quem pode os dois. */
    private function guard(Request $request): void
    {
        abort_unless(Access::allows($request->attributes->get('commerce.membership'), 'commerce.catalog.manage'), 403);
    }
}
