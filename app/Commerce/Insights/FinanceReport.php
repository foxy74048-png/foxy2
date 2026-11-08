<?php

namespace App\Commerce\Insights;

use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Product;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Números do painel financeiro para um período e filtros (sempre dentro da loja do contexto).
 * Venda = pedido pago, separando, enviado ou concluído, pela data do pedido.
 * Lucro bruto = total − frete − taxas do canal − custo das mercadorias.
 */
class FinanceReport
{
    public const PERIODS = [
        'hoje' => 'Hoje',
        '7d' => 'Últimos 7 dias',
        '30d' => 'Últimos 30 dias',
        'mes' => 'Este mês',
        'mes_anterior' => 'Mês passado',
        '90d' => 'Últimos 90 dias',
        'ano' => 'Este ano',
        '12m' => 'Últimos 12 meses',
        'personalizado' => 'Escolher datas',
    ];

    public Carbon $from;

    public Carbon $to;

    public function __construct(public array $filters)
    {
        [$this->from, $this->to] = self::range($filters['periodo'] ?? '30d', $filters['de'] ?? null, $filters['ate'] ?? null);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function range(string $period, ?string $from = null, ?string $to = null): array
    {
        $now = now();

        return match ($period) {
            'hoje' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'mes' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'mes_anterior' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            '90d' => [$now->copy()->subDays(89)->startOfDay(), $now->copy()->endOfDay()],
            'ano' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            '12m' => [$now->copy()->subMonthsNoOverflow(11)->startOfMonth(), $now->copy()->endOfDay()],
            'personalizado' => [
                Carbon::parse($from ?: $now->copy()->subDays(29))->startOfDay(),
                Carbon::parse($to ?: $now)->endOfDay(),
            ],
            default => [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()],
        };
    }

    /** Pedidos de um intervalo com os filtros de canal, forma de pagamento e categoria. */
    private function orders(Carbon $from, Carbon $to, ?array $statuses = Order::SOLD)
    {
        return Order::query()
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->whereBetween('placed_at', [$from, $to])
            ->when($this->filters['canal'] ?? null, fn ($q, $c) => $q->where('channel', $c))
            ->when($this->filters['pagamento'] ?? null, fn ($q, $p) => $q->where('payment_method', $p))
            ->when($this->filters['categoria'] ?? null, fn ($q, $cat) => $q->whereHas('items', fn ($i) => $i->whereIn('product_id', Product::where('category_id', $cat)->select('id'))));
    }

    public function kpis(): array
    {
        $current = $this->totals($this->from, $this->to);
        $days = $this->from->diffInDays($this->to) + 1;
        $previous = $this->totals($this->from->copy()->subDays((int) ceil($days)), $this->from->copy()->subSecond());

        $delta = fn (string $key) => $previous[$key] > 0 ? round(($current[$key] - $previous[$key]) / abs($previous[$key]) * 100, 1) : null;

        $all = $this->orders($this->from, $this->to, null)->get(['status', 'total_cents']);
        $canceled = $all->where('status', 'canceled');
        $awaiting = $this->orders(now()->subYears(5), now(), ['awaiting_payment'])->get(['total_cents']);

        return $current + [
            'delta_revenue' => $delta('revenue'),
            'delta_orders' => $delta('orders'),
            'delta_ticket' => $delta('ticket'),
            'delta_profit' => $delta('profit'),
            'canceled_count' => $canceled->count(),
            'canceled_cents' => (int) $canceled->sum('total_cents'),
            'cancel_rate' => $all->count() > 0 ? round($canceled->count() / $all->count() * 100, 1) : 0,
            'awaiting_count' => $awaiting->count(),
            'awaiting_cents' => (int) $awaiting->sum('total_cents'),
            'new_customers' => Customer::whereBetween('created_at', [$this->from, $this->to])->count(),
        ];
    }

    private function totals(Carbon $from, Carbon $to): array
    {
        $o = $this->orders($from, $to)->get(['total_cents', 'shipping_cents', 'fee_cents', 'cost_cents', 'discount_cents']);
        $revenue = (int) $o->sum('total_cents');
        $net = $revenue - (int) $o->sum('shipping_cents');
        $profit = $net - (int) $o->sum('fee_cents') - (int) $o->sum('cost_cents');

        return [
            'revenue' => $revenue,
            'orders' => $o->count(),
            'ticket' => $o->count() > 0 ? intdiv($revenue, $o->count()) : 0,
            'fees' => (int) $o->sum('fee_cents'),
            'cost' => (int) $o->sum('cost_cents'),
            'discounts' => (int) $o->sum('discount_cents'),
            'shipping' => (int) $o->sum('shipping_cents'),
            'profit' => $profit,
            'margin' => $net > 0 ? round($profit / $net * 100, 1) : 0,
        ];
    }

    /**
     * Faturamento e lucro por dia (até 62 dias) ou por mês.
     *
     * @return array{unit: string, points: array<int, array{label: string, full: string, revenue: int, profit: int, orders: int}>}
     */
    public function series(): array
    {
        // Até 2 meses: por dia; até ~6 meses: por semana (segunda a domingo); acima: por mês.
        $span = $this->from->diffInDays($this->to);
        $unit = $span <= 62 ? 'dia' : ($span <= 200 ? 'semana' : 'mês');
        $months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
        $start = fn (Carbon $d) => match ($unit) {
            'dia' => $d->copy()->startOfDay(),
            'semana' => $d->copy()->startOfWeek(Carbon::MONDAY),
            default => $d->copy()->startOfMonth(),
        };

        $orders = $this->orders($this->from, $this->to)->get(['placed_at', 'total_cents', 'shipping_cents', 'fee_cents', 'cost_cents']);
        $grouped = $orders->groupBy(fn (Order $o) => $start($o->placed_at)->format('Y-m-d'));

        $points = [];
        for ($cursor = $start($this->from); $cursor <= $this->to; $unit === 'mês' ? $cursor->addMonthNoOverflow() : $cursor->addDays($unit === 'semana' ? 7 : 1)) {
            $group = $grouped->get($cursor->format('Y-m-d'), collect());
            $points[] = [
                'label' => $unit === 'mês' ? $months[$cursor->month - 1] : $cursor->format('d/m'),
                'full' => match ($unit) {
                    'dia' => $cursor->format('d/m/Y'),
                    'semana' => 'Semana de '.$cursor->format('d/m/Y'),
                    default => $months[$cursor->month - 1].'/'.$cursor->year,
                },
                'revenue' => (int) $group->sum('total_cents'),
                'profit' => (int) $group->sum(fn (Order $o) => $o->profitCents()),
                'orders' => $group->count(),
            ];
        }

        return ['unit' => $unit, 'points' => $points];
    }

    /** Por canal: faturamento, pedidos, taxas, lucro e margem. */
    public function byChannel(): Collection
    {
        return $this->orders($this->from, $this->to)->get(['channel', 'total_cents', 'shipping_cents', 'fee_cents', 'cost_cents'])
            ->groupBy('channel')->map(fn ($g, $channel) => $this->row(Order::CHANNELS[$channel] ?? $channel, $g, $channel))
            ->sortByDesc('revenue')->values();
    }

    public function byPayment(): Collection
    {
        return $this->orders($this->from, $this->to)->get(['payment_method', 'total_cents', 'shipping_cents', 'fee_cents', 'cost_cents'])
            ->groupBy(fn ($o) => $o->payment_method ?? 'indefinido')
            ->map(fn ($g, $m) => $this->row(Order::PAYMENT_METHODS[$m] ?? 'Não informado', $g, $m))
            ->sortByDesc('revenue')->values();
    }

    private function row(string $label, Collection $orders, string $key): array
    {
        $revenue = (int) $orders->sum('total_cents');
        $net = $revenue - (int) $orders->sum('shipping_cents');
        $profit = $net - (int) $orders->sum('fee_cents') - (int) $orders->sum('cost_cents');

        return [
            'key' => $key, 'label' => $label, 'revenue' => $revenue, 'orders' => $orders->count(),
            'fees' => (int) $orders->sum('fee_cents'), 'profit' => $profit,
            'margin' => $net > 0 ? round($profit / $net * 100, 1) : 0,
            'ticket' => $orders->count() ? intdiv($revenue, $orders->count()) : 0,
        ];
    }

    /** Itens vendidos no período (respeita o filtro de categoria também no item). */
    private function items()
    {
        return OrderItem::whereIn('order_id', $this->orders($this->from, $this->to)->select('id'))
            ->when($this->filters['categoria'] ?? null, fn ($q, $cat) => $q->whereIn('product_id', Product::where('category_id', $cat)->select('id')));
    }

    public function topProducts(int $limit = 10): Collection
    {
        $items = $this->items()->get(['product_id', 'name', 'quantity', 'total_cents', 'unit_cost_cents']);
        $names = Product::whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('name', 'id');

        return $items->groupBy(fn ($i) => $i->product_id ?? $i->name)->map(fn ($g, $id) => [
            'label' => $names[$id] ?? $g->first()->name,
            'quantity' => (int) $g->sum('quantity'),
            'revenue' => (int) $g->sum('total_cents'),
            'profit' => (int) $g->sum(fn ($i) => $i->total_cents - $i->unit_cost_cents * $i->quantity),
        ])->sortByDesc('revenue')->take($limit)->values();
    }

    public function byCategory(): Collection
    {
        $items = $this->items()->get(['product_id', 'quantity', 'total_cents']);
        $products = Product::whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('category_id', 'id');
        $categories = Category::with('parent')->get()->keyBy('id');

        return $items->groupBy(fn ($i) => $products[$i->product_id] ?? 'sem')->map(fn ($g, $cat) => [
            'label' => $categories->get($cat)?->fullName() ?? 'Sem categoria',
            'quantity' => (int) $g->sum('quantity'),
            'revenue' => (int) $g->sum('total_cents'),
        ])->sortByDesc('revenue')->values();
    }

    public function topCustomers(int $limit = 8): Collection
    {
        $orders = $this->orders($this->from, $this->to)->whereNotNull('customer_id')->get(['customer_id', 'total_cents']);
        $names = Customer::whereIn('id', $orders->pluck('customer_id')->unique())->pluck('name', 'id');

        return $orders->groupBy('customer_id')->map(fn ($g, $id) => [
            'id' => $id, 'label' => $names[$id] ?? '—', 'orders' => $g->count(), 'revenue' => (int) $g->sum('total_cents'),
        ])->sortByDesc('revenue')->take($limit)->values();
    }
}
