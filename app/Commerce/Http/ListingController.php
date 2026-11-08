<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Product;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\ChannelSync;
use App\Commerce\Channels\Listing;
use App\Commerce\Channels\ListingVariant;
use App\Commerce\Channels\PublishListing;
use App\Commerce\Inventory\StockService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Anúncios criados no Foxy (docs/09, seção 6.2): um produto pode ter um anúncio por conta de marketplace,
 * com título e preço próprios. O estoque vai sozinho a partir das variantes ligadas.
 */
class ListingController
{
    public function __construct(private ChannelSync $sync) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'conexao' => ['nullable', 'string', 'max:30'],
            'situacao' => ['nullable', Rule::in(array_keys(Listing::STATUSES))],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);

        return view('commerce.listings.index', [
            'listings' => Listing::with('connection', 'product.images.file', 'variants')
                ->when($filters['conexao'] ?? null, fn ($q, $c) => $q->where('connection_id', $c))
                ->when($filters['situacao'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->when($filters['busca'] ?? null, fn ($q, $t) => $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%'))
                ->latest()->paginate(30)->withQueryString(),
            'connections' => ChannelConnection::where('status', '!=', 'disconnected')->get(),
            'filters' => $filters,
            'counts' => Listing::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function create(Request $request): View
    {
        $product = $request->query('produto') ? Product::with('variants')->find($request->query('produto')) : null;

        return view('commerce.listings.create', [
            'connections' => ChannelConnection::where('status', 'active')->get(),
            'products' => Product::where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'product' => $product,
            'available' => $product ? app(StockService::class)->availability($product->variants) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $connection = ChannelConnection::where('status', 'active')->findOrFail($data['connection_id']);
        $product = Product::where('status', 'active')->findOrFail($data['product_id']);
        $variantIds = $product->variants()->where('status', 'active')->whereIn('id', $data['variants'] ?? [])->pluck('id');
        if ($variantIds->isEmpty()) {
            throw ValidationException::withMessages(['variants' => 'Escolha pelo menos uma variante para o anúncio.']);
        }
        $this->guardTitle($data['title'], $connection->channel);

        $listing = DB::transaction(function () use ($data, $connection, $product, $variantIds) {
            $listing = Listing::create([
                'connection_id' => $connection->id, 'product_id' => $product->id, 'title' => $data['title'],
                'price_cents' => $data['price_cents'], 'external_category' => $data['external_category'] ?? null, 'status' => 'draft',
            ]);
            foreach ($variantIds as $id) {
                ListingVariant::create(['listing_id' => $listing->id, 'variant_id' => $id]);
            }
            Audit::record('commerce.listing.created', subject: $listing);

            return $listing;
        });

        if ($request->boolean('publish')) {
            return $this->publish($listing);
        }

        return redirect()->route('commerce.listings.edit', $listing)->with('status', 'Anúncio salvo como rascunho.');
    }

    public function edit(Listing $listing): View
    {
        $listing->load('connection', 'product', 'variants.variant');

        return view('commerce.listings.edit', [
            'listing' => $listing,
            'available' => app(StockService::class)->availability($listing->variants->pluck('variant')),
        ]);
    }

    public function update(Request $request, Listing $listing): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:20'],
            'external_category' => ['nullable', 'string', 'max:80'],
        ], [], ['title' => 'título', 'price' => 'preço', 'external_category' => 'categoria no canal']);
        $this->guardTitle($data['title'], $listing->connection->channel);
        $price = $this->price($data['price']);

        $listing->update(['title' => $data['title'], 'price_cents' => $price, 'external_category' => $data['external_category'] ?? null]);
        if (in_array($listing->status, ['active', 'paused'], true)) {
            $this->sync->update($listing);
        }

        return back()->with($listing->last_error ? 'error' : 'status', $listing->last_error ? 'Salvo no Foxy, mas o canal recusou: '.$listing->last_error : 'Anúncio salvo.');
    }

    public function publish(Listing $listing): RedirectResponse
    {
        if (! in_array($listing->status, ['draft', 'error'], true)) {
            throw ValidationException::withMessages(['status' => 'Este anúncio já foi publicado.']);
        }
        if (! $listing->connection->isActive()) {
            throw ValidationException::withMessages(['status' => 'A conta do '.$listing->connection->label().' não está conectada.']);
        }

        $listing->update(['status' => 'publishing', 'last_error' => null]);
        PublishListing::dispatch(ExecutionContext::current()->organization->getKey(), $listing->id);

        return redirect()->route('commerce.listings.edit', $listing)->with('status', 'Publicando no '.$listing->connection->label().'. Em alguns instantes a situação muda aqui.');
    }

    public function pause(Request $request, Listing $listing): RedirectResponse
    {
        $paused = $request->boolean('paused');
        if (! in_array($listing->status, $paused ? ['active'] : ['paused'], true)) {
            throw ValidationException::withMessages(['status' => 'Ação não disponível para "'.$listing->statusLabel().'".']);
        }
        $this->sync->pause($listing, $paused);

        return back()->with('status', $paused ? 'Anúncio pausado.' : 'Anúncio reativado.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'connection_id' => ['required', 'string', 'max:30'],
            'product_id' => ['required', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:255'],
            'price' => ['required', 'string', 'max:20'],
            'external_category' => ['nullable', 'string', 'max:80'],
            'variants' => ['nullable', 'array'],
            'variants.*' => ['string', 'max:30'],
        ], [], ['connection_id' => 'conta do marketplace', 'product_id' => 'produto', 'title' => 'título', 'price' => 'preço']);
        $data['price_cents'] = $this->price($data['price']);

        return $data;
    }

    private function price(string $value): int
    {
        $price = Money::toCents($value);
        if (! $price || $price <= 0) {
            throw ValidationException::withMessages(['price' => 'Preço inválido. Use, por exemplo, 89,90.']);
        }

        return $price;
    }

    private function guardTitle(string $title, string $channel): void
    {
        $max = config("commerce.channels.{$channel}.title_max", 255);
        if (mb_strlen($title) > $max) {
            throw ValidationException::withMessages(['title' => "O título passa do limite do canal ({$max} caracteres)."]);
        }
    }
}
