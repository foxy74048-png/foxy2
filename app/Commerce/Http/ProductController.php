<?php

namespace App\Commerce\Http;

use App\Commerce\Billing\Plans;
use App\Commerce\Catalog\CatalogService;
use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\ProductImage;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Files\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Catálogo do lojista (docs/09, fatia 3). Produto e variantes são achados pelo escopo da loja (BelongsToOrganization):
 * id de outra loja dá 404.
 */
class ProductController
{
    public const MAX_IMAGES = 10;

    public function __construct(private CatalogService $catalog) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:30'],
            'situacao' => ['nullable', Rule::in(array_keys(Product::STATUSES))],
        ]);

        $products = Product::with(['category', 'variants', 'images.file'])
            ->when($filters['busca'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereHas('variants', fn ($v) => $v->whereLike('sku', $like)));
            })
            ->when($filters['categoria'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['situacao'] ?? null, fn ($q, $status) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('name')->paginate(30)->withQueryString();

        return view('commerce.products.index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::with('parent')->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('commerce.products.create', [
            'kit' => $request->query('tipo') === 'kit',
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'pieces' => $request->query('tipo') === 'kit' ? $this->pieces() : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardLimit();
        $kit = $request->input('type') === 'kit';
        $data = $this->validateProduct($request) + $this->validatePrice($request);

        if ($kit) {
            $product = $this->catalog->createKit($data, $this->components($request));
        } else {
            [$names, $values] = CatalogService::parseGrid($request->input('grid', []));
            $product = $this->catalog->create($data, $names, $values);
        }

        return redirect()->route('commerce.products.edit', $product)
            ->with('status', $kit ? 'Kit criado.' : 'Produto criado com '.$product->variants()->count().' variante(s). Confira SKUs e preços abaixo.');
    }

    public function edit(Product $product): View
    {
        $product->load(['variants.components.component.product', 'images.file', 'category']);

        return view('commerce.products.edit', [
            'product' => $product,
            'categories' => Category::with('parent')->orderBy('name')->get(),
            'pieces' => $product->isKit() ? $this->pieces() : collect(),
            'available' => app(StockService::class)->availability($product->variants),
        ]);
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validateProduct($request, withStatus: $product->status !== 'archived');
        // Arquivado continua arquivado: volta só pelo botão "Reativar".
        if ($product->status === 'archived') {
            unset($data['status']);
        }
        $product->update($data);
        Audit::record('commerce.product.updated', subject: $product);

        return back()->with('status', 'Produto salvo.');
    }

    public function updateVariants(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'variants' => ['required', 'array', 'max:'.CatalogService::MAX_VARIANTS],
            'variants.*.sku' => ['required', 'string', 'max:55', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'variants.*.price' => ['required', 'string', 'max:20'],
            'variants.*.cost' => ['nullable', 'string', 'max:20'],
            'variants.*.gtin' => ['nullable', 'digits_between:8,14'],
            'variants.*.status' => ['required', Rule::in(['active', 'inactive'])],
        ], ['variants.*.sku.regex' => 'SKU só com letras, números, ponto, hífen e sublinhado.'], ['variants.*.sku' => 'SKU', 'variants.*.gtin' => 'código de barras']);

        $rows = [];
        foreach ($request->input('variants') as $id => $row) {
            $price = Money::toCents($row['price']);
            if ($price === null || $price < 0) {
                throw ValidationException::withMessages(['variants' => 'Preço inválido em '.$row['sku'].'.']);
            }
            $rows[$id] = ['sku' => $row['sku'], 'price_cents' => $price, 'cost_cents' => Money::toCents($row['cost'] ?? null), 'gtin' => $row['gtin'] ?? null, 'status' => $row['status']];
        }

        $this->catalog->updateVariants($product, $rows);

        return back()->with('status', 'Variantes salvas.');
    }

    public function extendGrid(Request $request, Product $product): RedirectResponse
    {
        $request->validate(['values' => ['required', 'array'], 'values.*' => ['nullable', 'string', 'max:500']]);
        $price = Money::toCents($request->input('price')) ?? (int) $product->variants()->value('price_cents');

        $new = [];
        foreach ($product->option_names ?? [] as $i => $name) {
            $new[$i] = collect(preg_split('/[,;\n]/', (string) ($request->input("values.{$i}") ?? '')))->map(fn ($v) => trim($v))->filter()->values()->all();
        }

        $added = $this->catalog->extendGrid($product, $new, $price);

        return back()->with('status', $added === 0 ? 'Nenhuma variante nova: essas combinações já existiam.' : "{$added} variante(s) nova(s) criada(s).");
    }

    public function updateComponents(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->isKit(), 404);
        $this->catalog->syncComponents($product->variants()->firstOrFail(), $this->components($request));
        Audit::record('commerce.kit.updated', subject: $product);

        return back()->with('status', 'Peças do kit salvas.');
    }

    public function archive(Product $product): RedirectResponse
    {
        $product->update(['status' => $product->status === 'archived' ? 'active' : 'archived']);
        Audit::record($product->status === 'archived' ? 'commerce.product.archived' : 'commerce.product.restored', subject: $product);

        return back()->with('status', $product->status === 'archived' ? 'Produto desativado: some das listas e da loja, mas o histórico fica. Dá para reativar quando quiser.' : 'Produto reativado.');
    }

    public function uploadImages(Request $request, Product $product): RedirectResponse
    {
        $request->validate([
            'images' => ['required', 'array', 'max:'.self::MAX_IMAGES],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [], ['images.*' => 'foto']);

        $count = $product->images()->count();
        if ($count + count($request->file('images')) > self::MAX_IMAGES) {
            throw ValidationException::withMessages(['images' => 'Cada produto pode ter até '.self::MAX_IMAGES.' fotos.']);
        }

        foreach ($request->file('images') as $upload) {
            $file = File::store($upload, 'commerce.product_image', config('commerce.images_disk'), 'commerce/products');
            ProductImage::create(['product_id' => $product->id, 'file_id' => $file->id, 'position' => $count++]);
        }

        return back()->with('status', 'Fotos enviadas.');
    }

    public function deleteImage(Product $product, string $image): RedirectResponse
    {
        $image = ProductImage::where('product_id', $product->id)->findOrFail($image);

        DB::transaction(function () use ($image) {
            $file = $image->file;
            $image->delete();
            $file?->purge();
        });

        return back()->with('status', 'Foto apagada.');
    }

    private function validateProduct(Request $request, bool $withStatus = true): array
    {
        $organizationId = ExecutionContext::current()->organization->getKey();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('commerce_categories', 'id')->where('organization_id', $organizationId)],
            'brand' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => [$withStatus ? 'required' : 'nullable', Rule::in(['active', 'draft'])],
            'specs.weight_g' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'specs.height_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'specs.width_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'specs.length_cm' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'specs.ncm' => ['nullable', 'digits:8'],
        ], [], ['name' => 'nome', 'category_id' => 'categoria', 'specs.ncm' => 'NCM', 'specs.weight_g' => 'peso']);

        $data['specs'] = array_filter($data['specs'] ?? [], fn ($v) => $v !== null && $v !== '') ?: null;

        return $data;
    }

    private function validatePrice(Request $request): array
    {
        $request->validate([
            'price' => ['required', 'string', 'max:20'],
            'cost' => ['nullable', 'string', 'max:20'],
            'sku' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/'],
        ], ['sku.regex' => 'SKU só com letras, números, ponto, hífen e sublinhado.'], ['price' => 'preço', 'cost' => 'custo']);

        $price = Money::toCents($request->input('price'));
        if ($price === null || $price < 0) {
            throw ValidationException::withMessages(['price' => 'Preço inválido. Use, por exemplo, 79,90.']);
        }

        return ['price_cents' => $price, 'cost_cents' => Money::toCents($request->input('cost')), 'sku' => $request->input('sku')];
    }

    /** Peças do kit vindas da tela: linhas de (SKU, quantidade) → [variant_id => quantidade]. */
    private function components(Request $request): array
    {
        $request->validate([
            'components' => ['required', 'array', 'max:30'],
            'components.*.sku' => ['nullable', 'string', 'max:55'],
            'components.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $result = [];
        foreach ($request->input('components') as $row) {
            if (blank($row['sku'] ?? null)) {
                continue;
            }
            $variant = Variant::where('sku', strtoupper(trim($row['sku'])))->first();
            if (! $variant) {
                throw ValidationException::withMessages(['components' => 'Não achamos o SKU '.$row['sku'].' nesta loja.']);
            }
            $result[$variant->id] = ($result[$variant->id] ?? 0) + (int) ($row['quantity'] ?? 1);
        }

        return $result;
    }

    /** Variantes que podem entrar num kit (lista de sugestões na tela). */
    private function pieces()
    {
        return Variant::with('product')->where('is_kit', false)->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('sku')->limit(2000)->get();
    }

    private function guardLimit(): void
    {
        $limit = Plans::limit(ExecutionContext::current()->organization, 'products');
        if ($limit !== null && Product::where('status', '!=', 'archived')->count() >= $limit) {
            throw ValidationException::withMessages(['name' => "O seu plano permite até {$limit} produtos ativos. Arquive produtos parados ou mude de plano em Assinatura."]);
        }
    }
}
