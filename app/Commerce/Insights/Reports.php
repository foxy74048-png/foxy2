<?php

namespace App\Commerce\Insights;

use App\Commerce\Catalog\Product;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatórios do Commerce (docs/09, fatia 11), sempre dentro da loja do contexto. Venda = pedido pago,
 * separando, enviado ou concluído.
 */
class Reports
{
    private const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /**
     * Últimos N meses (o atual incluído), com variação sobre o mês anterior.
     *
     * @return Collection<int, array{label: string, full: string, revenue: int, orders: int, ticket: int, profit: int, margin: float, delta: ?float}>
     */
    public function monthly(int $months = 12, ?string $channel = null): Collection
    {
        $start = now()->startOfMonth()->subMonthsNoOverflow($months);
        $orders = Order::sold()->where('placed_at', '>=', $start)
            ->when($channel, fn ($q) => $q->where('channel', $channel))
            ->get(['placed_at', 'total_cents', 'shipping_cents', 'fee_cents', 'cost_cents'])
            ->groupBy(fn (Order $o) => $o->placed_at->format('Y-m'));

        $rows = collect();
        for ($i = $months; $i >= 0; $i--) {
            $month = now()->startOfMonth()->subMonthsNoOverflow($i);
            $group = $orders->get($month->format('Y-m'), collect());
            $revenue = (int) $group->sum('total_cents');
            $net = $revenue - (int) $group->sum('shipping_cents');
            $profit = $net - (int) $group->sum('fee_cents') - (int) $group->sum('cost_cents');
            $rows->push([
                'label' => self::MONTHS[$month->month - 1],
                'full' => self::MONTHS[$month->month - 1].'/'.$month->year.($i === 0 ? ' (até hoje)' : ''),
                'current' => $i === 0,
                'revenue' => $revenue, 'orders' => $group->count(), 'profit' => $profit,
                'ticket' => $group->count() ? intdiv($revenue, $group->count()) : 0,
                'margin' => $net > 0 ? round($profit / $net * 100, 1) : 0.0,
            ]);
        }

        // O mês atual ainda não acabou: compara com os mesmos dias do mês anterior (dia 1 até hoje).
        $samePeriod = (int) Order::sold()->when($channel, fn ($q) => $q->where('channel', $channel))
            ->whereBetween('placed_at', [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()])->sum('total_cents');
        $last = $rows->count() - 1;

        // O primeiro mês serve só de base para a variação do segundo.
        return $rows->map(function ($row, $i) use ($rows, $samePeriod, $last) {
            $previous = $i === $last ? $samePeriod : ($i > 0 ? $rows[$i - 1]['revenue'] : 0);

            return $row + ['delta' => $i > 0 && $previous > 0 ? round(($row['revenue'] - $previous) / $previous * 100, 1) : null];
        })->slice(1)->values();
    }

    /**
     * Estoque parado e giro por variante (kits ficam de fora: o estoque deles é o das peças).
     *
     * @return Collection<int, array{variant: Variant, on_hand: int, sold: int, per_day: float, coverage: ?int, last_sale: ?Carbon, stuck_cents: int, status: string}>
     */
    public function stock(int $days = 90): Collection
    {
        $since = now()->subDays($days)->startOfDay();
        $variants = Variant::with('product')->where('is_kit', false)->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', '!=', 'archived'))->get();
        $levels = StockLevel::whereIn('variant_id', $variants->pluck('id'))->pluck('on_hand', 'variant_id');

        // Vendas diretas + peças vendidas dentro de kits.
        $soldOrders = Order::sold()->select('id');
        $items = OrderItem::whereIn('order_id', $soldOrders)->with('variant.components')->get(['order_id', 'variant_id', 'quantity']);
        $placed = Order::sold()->pluck('placed_at', 'id');
        $sold = [];
        $last = [];
        foreach ($items as $item) {
            $date = Carbon::parse($placed[$item->order_id]);
            $pieces = $item->variant?->is_kit ? $item->variant->components->map(fn ($c) => [$c->component_variant_id, $c->quantity * $item->quantity]) : collect([[$item->variant_id, $item->quantity]]);
            foreach ($pieces as [$id, $qty]) {
                if ($date->gte($since)) {
                    $sold[$id] = ($sold[$id] ?? 0) + $qty;
                }
                if (! isset($last[$id]) || $date->gt($last[$id])) {
                    $last[$id] = $date;
                }
            }
        }

        return $variants->map(function (Variant $v) use ($levels, $sold, $last, $days) {
            $onHand = (int) ($levels[$v->id] ?? 0);
            $qty = $sold[$v->id] ?? 0;
            $perDay = $qty / max(1, $days);
            $coverage = $perDay > 0 ? (int) floor(max(0, $onHand) / $perDay) : null;

            return [
                'variant' => $v, 'on_hand' => $onHand, 'sold' => $qty, 'per_day' => round($perDay, 2), 'coverage' => $coverage,
                'last_sale' => $last[$v->id] ?? null, 'stuck_cents' => max(0, $onHand) * (int) $v->cost_cents,
                'status' => match (true) {
                    $onHand <= 0 => 'sem_estoque',
                    $qty === 0 => 'parado',
                    $coverage !== null && $coverage > 120 => 'lento',
                    $coverage !== null && $coverage < 15 => 'acabando',
                    default => 'saudavel',
                },
            ];
        })->sortByDesc('stuck_cents')->values();
    }

    /**
     * Curva ABC por faturamento no período: A até 80% acumulado, B até 95%, C o resto.
     *
     * @return Collection<int, array{label: string, revenue: int, quantity: int, profit: int, share: float, cumulative: float, class: string}>
     */
    public function abc(Carbon $from, Carbon $to): Collection
    {
        $items = OrderItem::whereIn('order_id', Order::sold()->whereBetween('placed_at', [$from, $to])->select('id'))
            ->get(['product_id', 'name', 'quantity', 'total_cents', 'unit_cost_cents']);
        $names = Product::whereIn('id', $items->pluck('product_id')->filter()->unique())->pluck('name', 'id');
        $total = max(1, (int) $items->sum('total_cents'));

        $cumulative = 0;

        return $items->groupBy(fn ($i) => $i->product_id ?? $i->name)
            ->map(fn ($g, $id) => [
                'label' => $names[$id] ?? $g->first()->name,
                'revenue' => (int) $g->sum('total_cents'),
                'quantity' => (int) $g->sum('quantity'),
                'profit' => (int) $g->sum(fn ($i) => $i->total_cents - $i->unit_cost_cents * $i->quantity),
            ])
            ->sortByDesc('revenue')->values()
            ->map(function ($row) use (&$cumulative, $total) {
                $share = $row['revenue'] / $total * 100;
                $before = $cumulative;
                $cumulative += $share;

                return $row + [
                    'share' => round($share, 1), 'cumulative' => round($cumulative, 1),
                    'class' => $before < 80 ? 'A' : ($before < 95 ? 'B' : 'C'),
                    'margin' => $row['revenue'] > 0 ? round($row['profit'] / $row['revenue'] * 100, 1) : 0.0,
                ];
            });
    }
}
