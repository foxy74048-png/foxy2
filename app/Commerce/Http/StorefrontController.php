<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Commerce\Storefront\Cart;
use App\Commerce\Storefront\PixCode;
use App\Commerce\Storefront\StorefrontOrderNotification;
use App\Platform\Memberships\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Loja própria do lojista em ecommerce.foxy.net.br/{slug} (docs/09, seção 7). Pública: o contexto é a loja do
 * slug (ResolvePublicStore). Pagamento por fora, no Pix do lojista; o pedido reserva as peças por 48 h.
 */
class StorefrontController
{
    public function __construct(private Cart $cart, private StockService $stock) {}

    /** Aviso de privacidade (LGPD) da loja: o que coleta, para quê e como pedir correção ou exclusão. */
    public function privacy(Request $request): View
    {
        return view('storefront.privacy');
    }

    public function home(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'categoria' => ['nullable', 'string', 'max:30']]);

        $query = Product::with(['images.file', 'variants' => fn ($q) => $q->where('status', 'active')])
            ->where('status', 'active')
            ->whereHas('variants', fn ($q) => $q->where('status', 'active'))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->whereLike('name', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%'))
            ->when($filters['categoria'] ?? null, fn ($q, $c) => $q->where(fn ($q) => $q->where('category_id', $c)->orWhereIn('category_id', Category::where('parent_id', $c)->select('id'))))
            ->orderBy('name');

        if ($this->store($request)->hide_sold_out) {
            // Só o que tem para pronta entrega (o disponível de conjunto/kit depende das peças, então filtra aqui).
            $all = $query->get();
            $available = $this->stock->availability($all->flatMap->variants);
            $all = $all->filter(fn (Product $p) => $p->variants->contains(fn ($v) => ($available[$v->id] ?? 0) > 0))->values();
            $page = LengthAwarePaginator::resolveCurrentPage();
            $products = (new LengthAwarePaginator($all->forPage($page, 24)->values(), $all->count(), 24, $page, ['path' => $request->url()]))->withQueryString();
        } else {
            $products = $query->paginate(24)->withQueryString();
            $available = $this->stock->availability($products->getCollection()->flatMap->variants);
        }

        return view('storefront.home', [
            'products' => $products,
            'available' => $available,
            'categories' => Category::whereNull('parent_id')->orderBy('name')->get(),
            'filters' => $filters,
            'cartCount' => $this->cart->count($this->store($request)),
        ]);
    }

    public function product(Request $request, string $slug, string $product): View
    {
        $product = Product::with(['images.file', 'category', 'variants' => fn ($q) => $q->where('status', 'active')])->where('status', 'active')->findOrFail($product);

        return view('storefront.product', [
            'product' => $product,
            'available' => $this->stock->availability($product->variants),
            'cartCount' => $this->cart->count($this->store($request)),
        ]);
    }

    /**
     * Modo catálogo: abre o WhatsApp da loja com o pedido escrito (produto, cor/tamanho, quantidade, preço e
     * o link). Só leitura: nada é reservado nem gravado.
     */
    public function ask(Request $request, string $slug, string $product): RedirectResponse
    {
        $store = $this->store($request);
        $data = $request->validate(['variant_id' => ['nullable', 'string', 'max:30'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:99']]);
        $product = Product::with(['variants' => fn ($q) => $q->where('status', 'active')])->where('status', 'active')->findOrFail($product);
        $variant = isset($data['variant_id']) ? $product->variants->firstWhere('id', $data['variant_id']) : $product->variants->first();
        abort_unless($variant, 404);
        if (($this->stock->availability(collect([$variant]))[$variant->id] ?? 0) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Esta opção esgotou. Escolha outra ou fale com a loja.']);
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $choice = $product->variants->count() > 1 ? ' — '.$variant->label() : '';
        $text = "Olá! Vi no catálogo da {$store->name} e quero:\n• {$quantity}× {$product->name}{$choice} — ".Money::format($variant->price_cents)
            ."\n".route('commerce.storefront.product', [$store->slug, $product->id]);
        $link = $store->whatsappLink($text);
        if (! $link) {
            throw ValidationException::withMessages(['quantity' => 'A loja ainda não informou o WhatsApp.']);
        }

        return redirect()->away($link);
    }

    public function add(Request $request): RedirectResponse
    {
        if ($this->store($request)->isCatalog()) {
            return redirect()->route('commerce.storefront.home', $this->store($request)->slug);
        }
        $data = $request->validate(['variant_id' => ['required', 'string', 'max:30'], 'quantity' => ['nullable', 'integer', 'min:1', 'max:99']]);
        $variant = Variant::where('status', 'active')->whereHas('product', fn ($q) => $q->where('status', 'active'))->findOrFail($data['variant_id']);
        $store = $this->store($request);

        $wanted = ($this->cart->raw($store)[$variant->id] ?? 0) + (int) ($data['quantity'] ?? 1);
        $available = max(0, $this->stock->availability(collect([$variant]))[$variant->id]);
        if ($wanted > $available) {
            throw ValidationException::withMessages(['quantity' => $available > 0 ? "Só temos {$available} disponível(is) desta opção." : 'Esta opção esgotou.']);
        }

        $this->cart->put($store, $variant->id, $wanted);

        return redirect()->route('commerce.storefront.cart', $store->slug)->with('status', 'Adicionado ao carrinho.');
    }

    public function cart(Request $request): View|RedirectResponse
    {
        $store = $this->store($request);
        if ($store->isCatalog()) {
            return redirect()->route('commerce.storefront.home', $store->slug);
        }

        return view('storefront.cart', ['lines' => $this->cart->lines($store), 'cartCount' => $this->cart->count($store)]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($this->store($request)->isCatalog()) {
            return redirect()->route('commerce.storefront.home', $this->store($request)->slug);
        }
        $data = $request->validate(['items' => ['required', 'array', 'max:50'], 'items.*' => ['integer', 'min:0', 'max:99']]);
        $store = $this->store($request);
        foreach ($data['items'] as $variantId => $quantity) {
            $this->cart->put($store, (string) $variantId, (int) $quantity);
        }

        return back()->with('status', 'Carrinho atualizado.');
    }

    public function checkout(Request $request): View|RedirectResponse
    {
        $store = $this->store($request);
        $lines = $this->cart->lines($store);
        if ($lines->isEmpty() || $store->isCatalog()) {
            return redirect()->route('commerce.storefront.home', $store->slug);
        }

        return view('storefront.checkout', ['lines' => $lines, 'cartCount' => $this->cart->count($store)]);
    }

    public function place(Request $request, OrderService $orders): RedirectResponse
    {
        $store = $this->store($request);
        $options = $store->deliveryOptions();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'min:10', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'delivery' => [$options ? 'required' : 'nullable', Rule::in(array_keys($options))],
            'address' => [Rule::requiredIf(in_array($request->input('delivery'), ['local', 'shipping'], true)), 'nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'nome', 'phone' => 'WhatsApp', 'delivery' => 'entrega', 'address' => 'endereço']);

        $lines = $this->cart->lines($store);
        if ($lines->isEmpty() || $store->isCatalog()) {
            return redirect()->route('commerce.storefront.home', $store->slug);
        }
        foreach ($lines as $line) {
            if ($line['quantity'] > $line['available']) {
                throw ValidationException::withMessages(['cart' => $line['variant']->fullName().': só temos '.$line['available'].' disponível(is). Ajuste o carrinho.']);
            }
        }

        $delivery = $options[$data['delivery'] ?? ''] ?? null;
        $notes = trim(implode("\n", array_filter([
            $delivery ? 'Entrega: '.$delivery['label'] : null,
            ! empty($data['address']) ? 'Endereço: '.$data['address'].(! empty($data['city']) ? ' — '.$data['city'] : '') : null,
            ! empty($data['notes']) ? 'Observação do comprador: '.$data['notes'] : null,
        ])));

        $order = $orders->place([
            'channel' => 'loja',
            'customer' => array_filter(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'city' => $data['city'] ?? null]),
            'items' => $lines->map(fn ($l) => ['variant' => $l['variant'], 'quantity' => $l['quantity']])->all(),
            'shipping_cents' => $delivery['fee_cents'] ?? 0,
            'payment_method' => 'pix',
            'notes' => $notes ?: null,
        ]);
        $this->cart->clear($store);

        $url = $this->orderUrl($store->slug, $order);
        $owner = Membership::active()->where('organization_id', $store->organization_id)->where('role', 'owner')->with('user')->first()?->user;
        $owner?->notify(new StorefrontOrderNotification($store->name, $order, route('commerce.orders.show', $order), forOwner: true));
        if (! empty($data['email'])) {
            Notification::route('mail', $data['email'])->notify(new StorefrontOrderNotification($store->name, $order, $url, forOwner: false));
        }

        return redirect()->to($url);
    }

    /** Página do pedido para o comprador (link assinado: só quem tem o link vê). */
    public function order(Request $request, string $slug, string $order): View
    {
        $order = Order::with('items')->where('channel', 'loja')->findOrFail($order);
        $store = $this->store($request);
        $pix = $store->pixReady() && $order->status === 'awaiting_payment'
            ? PixCode::payload($store->pix_key, $store->pix_name, $store->pix_city, $order->total_cents, 'PEDIDO'.$order->number)
            : null;

        $summary = "Olá! Fiz o pedido #{$order->number} na {$store->name}:\n"
            .$order->items->map(fn ($i) => "• {$i->quantity}× {$i->name}")->implode("\n")
            ."\nTotal: ".Money::format($order->total_cents)."\n".$this->orderUrl($store->slug, $order);

        return view('storefront.order', [
            'order' => $order,
            'pix' => $pix,
            'qr' => $pix ? PixCode::qrSvg($pix) : null,
            'whatsapp' => $store->whatsappLink($summary),
            'cartCount' => $this->cart->count($store),
        ]);
    }

    private function store(Request $request)
    {
        return $request->attributes->get('storefront.store');
    }

    private function orderUrl(string $slug, Order $order): string
    {
        return URL::signedRoute('commerce.storefront.order', ['slug' => $slug, 'order' => $order->id]);
    }
}
