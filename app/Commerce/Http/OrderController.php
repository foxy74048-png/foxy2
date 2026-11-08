<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Variant;
use App\Commerce\Insights\Csv;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pedidos de todos os canais numa fila só (docs/09, fatia 5). Pedido manual = balcão, WhatsApp, Instagram.
 */
class OrderController
{
    public function __construct(private OrderService $orders) {}

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        return view('commerce.orders.index', [
            'orders' => $this->query($filters)->with('customer')->latest('placed_at')->paginate(30)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    /** Planilha (CSV, abre no Excel) com os pedidos dos mesmos filtros da lista. */
    public function export(Request $request): StreamedResponse
    {
        $orders = $this->query($this->filters($request))->with('customer', 'fiscalDocuments')->orderBy('placed_at')->get();

        return response()->streamDownload(function () use ($orders) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $money = fn (int $c) => number_format($c / 100, 2, ',', '');
            Csv::row($out, ['Pedido', 'Data', 'Canal', 'Status', 'Cliente', 'Pagamento', 'Subtotal', 'Desconto', 'Frete', 'Taxas do canal', 'Total', 'Custo', 'Lucro bruto', 'Nota fiscal', 'Chave NF-e']);
            foreach ($orders as $o) {
                Csv::row($out, [
                    $o->number, $o->placed_at->format('d/m/Y H:i'), $o->channelLabel(), $o->statusLabel(), $o->customer?->name,
                    Order::PAYMENT_METHODS[$o->payment_method] ?? '', $money($o->subtotal_cents), $money($o->discount_cents),
                    $money($o->shipping_cents), $money($o->fee_cents), $money($o->total_cents), $money($o->cost_cents), $money($o->profitCents()),
                    $o->fiscalDocuments->first()?->number, $o->fiscalDocuments->first()?->access_key ? '​'.$o->fiscalDocuments->first()->access_key : null,
                ]);
            }
            fclose($out);
        }, 'pedidos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
                    ->orWhere('external_id', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)->orWhere('phone', 'like', $like)));
            });
    }
}
