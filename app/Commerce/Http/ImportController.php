<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Billing\Plans;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\ProductImage;
use App\Commerce\Imports\FinanceImport;
use App\Commerce\Imports\PhotoMatcher;
use App\Commerce\Imports\ProductImport;
use App\Commerce\Imports\Sheet;
use App\Commerce\Insights\Csv;
use App\Platform\Context\ExecutionContext;
use App\Platform\Files\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trazer o que a loja já tem: planilha de produtos e estoque, planilhas do financeiro (a receber e a pagar)
 * e fotos em lote. Sempre: envia → confere a prévia → lança. A prévia fica guardada por 2 horas.
 */
class ImportController
{
    public const TYPES = ['produtos' => 'Produtos e estoque', 'receber' => 'Contas a receber', 'pagar' => 'Contas a pagar'];

    public function __construct(private ProductImport $products, private FinanceImport $finance) {}

    public function index(Request $request): View
    {
        abort_unless($this->allowed($request, 'produtos') || $this->allowed($request, 'receber'), 403);

        return view('commerce.imports.index', [
            'canProducts' => $this->allowed($request, 'produtos'),
            'canFinance' => $this->allowed($request, 'receber'),
            'canSupply' => Access::allows($request->attributes->get('commerce.membership'), 'commerce.catalog.manage'),
        ]);
    }

    /** Modelo em CSV (abre no Excel), com exemplos de uma loja de lingerie. */
    public function template(Request $request, string $type): StreamedResponse
    {
        $this->guard($request, $type);
        $rows = $type === 'produtos' ? ProductImport::SAMPLE : FinanceImport::SAMPLE[$type];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                Csv::row($out, $row);
            }
            fclose($out);
        }, 'modelo-'.$type.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function upload(Request $request, string $type): RedirectResponse
    {
        $this->guard($request, $type);
        $request->validate(['file' => ['required', 'file', 'max:10240']], [], ['file' => 'planilha']);
        // No PDF, os títulos conhecidos ajudam a achar a tabela no meio do relatório.
        $known = array_merge(...array_values($type === 'produtos' ? ProductImport::COLUMNS : FinanceImport::COLUMNS));
        $rows = Sheet::read($request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $known);
        $preview = $type === 'produtos' ? $this->products->preview($rows) : $this->finance->preview($type, $rows);
        if ($preview['missing'] !== []) {
            throw ValidationException::withMessages(['file' => 'Faltou a coluna '.implode(' e ', array_map(fn ($c) => "\"{$c}\"", $preview['missing'])).' na planilha. Confira os títulos da primeira linha (veja o modelo).']);
        }

        $token = Str::lower(Str::random(24));
        Cache::put($this->key($token), ['type' => $type, 'file' => Str::limit($request->file('file')->getClientOriginalName(), 80), 'preview' => $preview], now()->addHours(2));

        return redirect()->route('commerce.imports.preview', [$type, $token]);
    }

    public function preview(Request $request, string $type, string $token): View|RedirectResponse
    {
        $this->guard($request, $type);
        $data = $this->cached($type, $token);

        return view('commerce.imports.preview', ['type' => $type, 'token' => $token, 'file' => $data['file'], 'preview' => $data['preview']]);
    }

    public function apply(Request $request, string $type, string $token): RedirectResponse
    {
        $this->guard($request, $type);
        $data = $this->cached($type, $token);
        $preview = $data['preview'];

        if ($type === 'produtos') {
            $mode = $request->validate(['stock_mode' => ['required', Rule::in(['somar', 'igualar'])]])['stock_mode'];
            $limit = Plans::limit(ExecutionContext::current()->organization, 'products');
            $new = collect($preview['groups'])->filter(fn ($g) => empty($g['error']) && empty($g['product_id']))->count();
            if ($limit !== null && Product::where('status', '!=', 'archived')->count() + $new > $limit) {
                throw ValidationException::withMessages(['stock_mode' => "A planilha criaria {$new} produto(s) novo(s) e o seu plano permite até {$limit}. Mude de plano em Assinatura ou envie uma planilha menor."]);
            }
            $s = $this->products->apply($preview['groups'], $mode, 'Importado da planilha '.$data['file']);
            Cache::forget($this->key($token));

            return redirect()->route('commerce.products')->with('status', "Planilha lançada: {$s['created']} produto(s) novo(s), {$s['updated']} atualizado(s), {$s['variants']} variação(ões) e {$s['units']} peça(s) no estoque.");
        }

        $s = $this->finance->apply($type, $preview['rows']);
        Cache::forget($this->key($token));

        return redirect()->route('commerce.finance', ['aba' => $type])->with('status', "Planilha lançada: {$s['created']} conta(s)".($s['paid'] ? ", {$s['paid']} já ".($type === 'receber' ? 'recebida(s)' : 'paga(s)') : '').($s['customers'] ? ", {$s['customers']} cliente(s) nova(s) cadastrada(s)" : '').'.');
    }

    public function photos(Request $request): View
    {
        $this->guard($request, 'fotos');

        return view('commerce.imports.photos', ['max' => ProductController::MAX_IMAGES]);
    }

    /** Uma foto por vez (a tela envia uma a uma, com o progresso). */
    public function photo(Request $request, PhotoMatcher $matcher): JsonResponse
    {
        $this->guard($request, 'fotos');
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [], ['image' => 'foto']);
        $upload = $request->file('image');
        $match = $matcher->match($upload->getClientOriginalName());
        if (! $match) {
            return response()->json(['ok' => false, 'message' => 'Não achamos o produto pelo nome do arquivo.'], 422);
        }
        $product = $match['product'];
        $count = $product->images()->count();
        if ($count >= ProductController::MAX_IMAGES) {
            return response()->json(['ok' => false, 'message' => "{$product->name} já tem ".ProductController::MAX_IMAGES.' fotos.'], 422);
        }

        $file = File::store($upload, 'commerce.product_image', config('commerce.images_disk'), 'commerce/products');
        ProductImage::create(['product_id' => $product->id, 'file_id' => $file->id, 'position' => $count,
            'option_name' => $match['option_name'], 'option_value' => $match['option_value']]);

        return response()->json(['ok' => true, 'product' => $product->name, 'url' => route('commerce.products.edit', $product),
            'option' => $match['option_value'] ? "{$match['option_name']}: {$match['option_value']}" : 'todas as variações']);
    }

    private function cached(string $type, string $token): array
    {
        $data = preg_match('/^[a-z0-9]{24}$/', $token) ? Cache::get($this->key($token)) : null;
        if (! $data || $data['type'] !== $type) {
            throw ValidationException::withMessages(['file' => 'Esta prévia expirou ou já foi lançada. Envie a planilha de novo.'])->redirectTo(route('commerce.imports'));
        }

        return $data;
    }

    /** A prévia é da loja (e da pessoa) que enviou. */
    private function key(string $token): string
    {
        $context = ExecutionContext::current();

        return 'commerce:import:'.$context->organization->getKey().':'.$context->principalId.':'.$token;
    }

    private function guard(Request $request, string $type): void
    {
        abort_unless($this->allowed($request, $type), 403);
    }

    private function allowed(Request $request, string $type): bool
    {
        $m = $request->attributes->get('commerce.membership');

        return match ($type) {
            'produtos' => Access::allows($m, 'commerce.catalog.manage') && Access::allows($m, 'commerce.stock.adjust'),
            'fotos' => Access::allows($m, 'commerce.catalog.manage'),
            'receber', 'pagar' => Access::allows($m, 'commerce.finance.manage'),
            default => false,
        };
    }
}
