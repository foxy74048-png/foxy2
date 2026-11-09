<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Variant;
use App\Commerce\Insights\Csv;
use App\Commerce\Insights\Export;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pedidos de todos os canais numa fila só (docs/09, fatia 5). Pedido manual = balcão, WhatsApp, Instagram.
 */
class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): View|Response
    {
        $filters = $this->filters($request);
        if ($format = Export::requested($request)) {
            return $this->exportAs($filters, $format);
        }

        return view('commerce.orders.index', [
            'orders' => $this->query($filters)->with('customer')->latest('placed_at')->paginate(30)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /** Planilha (CSV, abre no Excel) com os pedidos dos mesmos filtros da lista. */
    /** Endereço antigo da planilha: continua funcionando. */
    public function export(Request $request): Response
    {
        return $this->exportAs($this->filters($request), 'planilha');
    }

    private function exportAs(array $filters, string $format): Response
    {
        $orders = $this->query($filters)->with('customer', 'fiscalDocuments')->orderBy('placed_at')->get();

        return (new Export('Pedidos'))
            ->filters([
                'Período' => ! empty($filters['de']) || ! empty($filters['ate']) ? trim(($filters['de'] ?? '…').' a '.($filters['ate'] ?? '…')) : FinanceReport::PERIODS[$filters['periodo'] ?? '90d'],
                'Situação' => isset($filters['status']) ? Order::STATUSES[$filters['status']] : null,
                'Canal' => isset($filters['canal']) ? Order::CHANNELS[$filters['canal']] : null,
                'Nota fiscal' => ['sem' => 'Sem nota', 'com' => 'Com nota'][$filters['nota'] ?? ''] ?? null,
                'Busca' => $filters['busca'] ?? null,
            ])
            ->section(null, ['Pedido' => 'int', 'Data' => 'datetime', 'Canal' => 'text', 'Situação' => 'text', 'Cliente' => 'text', 'Pagamento' => 'text',
                'Subtotal' => 'money', 'Desconto' => 'money', 'Frete' => 'money', 'Taxas do canal' => 'money', 'Total' => 'money', 'Custo' => 'money', 'Lucro bruto' => 'money', 'Nota fiscal' => 'text', 'Chave NF-e' => 'text:planilha'],
                $orders->map(fn (Order $o) => [
                    $o->number, $o->placed_at, $o->channelLabel(), $o->statusLabel(), $o->customer?->name, Order::PAYMENT_METHODS[$o->payment_method] ?? null,
                    $o->subtotal_cents, $o->discount_cents, $o->shipping_cents, $o->fee_cents, $o->total_cents, $o->cost_cents, $o->status === 'canceled' ? null : $o->profitCents(),
                    $o->fiscalDocuments->first()?->number, $o->fiscalDocuments->first()?->access_key ? "\u{200B}".$o->fiscalDocuments->first()->access_key : null,
                ]))
            ->download($format);
    }

    public function create(): View
    {
        return view('commerce.orders.create', [
            'variants' => Variant::with('product')->where('status', 'active')->whereHas('product', fn ($q) => $q->where('status', 'active'))->orderBy('sku')->limit(3000)->get(),
            'customers' => Customer::orderBy('name')->limit(3000)->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(Order::MANUAL_CHANNELS)],
            'customer_id' => ['nullable', 'string', 'max:30'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'items' => ['required', 'array', 'max:100'],
            'items.*.sku' => ['nullable', 'string', 'max:55'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['nullable', 'string', 'max:20'],
            'discount' => ['nullable', 'string', 'max:20'],
            'shipping' => ['nullable', 'string', 'max:20'],
            'paid' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', Rule::in(array_keys(Order::PAYMENT_METHODS))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['channel' => 'canal', 'customer.email' => 'e-mail do cliente']);

        $customerId = filled($data['customer_id'] ?? null) ? Customer::findOrFail($data['customer_id'])->id : null;
        $items = [];
        foreach ($data['items'] as $row) {
            if (blank($row['sku'] ?? null)) {
                continue;
            }
            $variant = Variant::where('sku', Str::upper(trim($row['sku'])))->first()
                ?? throw ValidationException::withMessages(['items' => 'Não achamos o SKU '.$row['sku'].' nesta loja.']);
            $items[] = ['variant' => $variant, 'quantity' => (int) ($row['quantity'] ?? 1), 'unit_price_cents' => Money::toCents($row['price'] ?? null) ?? $variant->price_cents];
        }

        $order = $this->orders->place([
            'channel' => $data['channel'],
            'customer_id' => $customerId,
            'customer' => $customerId ? null : array_filter($data['customer'] ?? []),
            'items' => $items,
            'discount_cents' => Money::toCents($data['discount'] ?? null) ?? 0,
            'shipping_cents' => Money::toCents($data['shipping'] ?? null) ?? 0,
            'paid' => (bool) ($data['paid'] ?? false),
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('commerce.orders.show', $order)->with('status', "Pedido #{$order->number} registrado.");
    }

    public function show(Order $order): View
    {
        return view('commerce.orders.show', ['order' => $order->load('items', 'customer', 'fiscalDocuments')]);
    }

    public function pay(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['payment_method' => ['nullable', Rule::in(array_keys(Order::PAYMENT_METHODS))]]);
        $this->orders->markPaid($order, $data['payment_method'] ?? null);

        return back()->with('status', 'Pagamento confirmado. O estoque foi baixado.');
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'tracking_code' => ['nullable', 'string', 'max:60'],
            'payment_method' => ['nullable', Rule::in(array_keys(Order::PAYMENT_METHODS))],
            'discount' => ['nullable', 'string', 'max:20'],
            'shipping' => ['nullable', 'string', 'max:20'],
        ], [], ['notes' => 'observações', 'tracking_code' => 'rastreio', 'discount' => 'desconto', 'shipping' => 'frete']);

        $changes = ['notes' => $data['notes'] ?? null];
        if ($request->has('tracking_code')) {
            $changes['tracking_code'] = $data['tracking_code'] ?? null;
        }
        if ($request->filled('payment_method')) {
            $changes['payment_method'] = $data['payment_method'];
        }
        if ($request->has('discount')) {
            $changes['discount_cents'] = Money::toCents($data['discount'] ?? null) ?? 0;
        }
        if ($request->has('shipping')) {
            $changes['shipping_cents'] = Money::toCents($data['shipping'] ?? null) ?? 0;
        }
        $this->orders->updateDetails($order, $changes);

        return back()->with('status', 'Pedido salvo.');
    }

    public function advance(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'string'], 'tracking_code' => ['nullable', 'string', 'max:60']]);
        $this->orders->advance($order, $data['status'], $data['tracking_code'] ?? null);

        return back()->with('status', 'Pedido agora está "'.$order->statusLabel().'".');
    }

    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $this->orders->cancel($order, $data['reason'] ?? null);

        return back()->with('status', 'Pedido cancelado. As peças voltaram ao estoque.');
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'periodo' => ['nullable', Rule::in(array_keys(FinanceReport::PERIODS))],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_keys(Order::STATUSES))],
            'canal' => ['nullable', Rule::in(array_keys(Order::CHANNELS))],
            'busca' => ['nullable', 'string', 'max:100'],
            'nota' => ['nullable', 'in:sem,com'],
        ]);
    }

    private function query(array $filters)
    {
        [$from, $to] = FinanceReport::range($filters['periodo'] ?? '90d', $filters['de'] ?? null, $filters['ate'] ?? null);

        return Order::query()
            ->whereBetween('placed_at', [$from, $to])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['canal'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when(($filters['nota'] ?? null) === 'sem', fn ($q) => $q->sold()->whereDoesntHave('fiscalDocuments'))
            ->when(($filters['nota'] ?? null) === 'com', fn ($q) => $q->whereHas('fiscalDocuments'))
            ->when($filters['busca'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->where('number', ctype_digit($term) ? (int) $term : 0)
                    ->orWhereLike('external_id', $like)
                    ->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)));
            });
    }
}
