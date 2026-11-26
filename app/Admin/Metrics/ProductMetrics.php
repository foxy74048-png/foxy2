<?php

namespace App\Admin\Metrics;

use App\Platform\Products\ProductAccount;
use App\Platform\Products\ProductAccountEvent;
use App\Platform\Products\ProductMetricDay;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Métricas do Foxy Admin (docs/08, seção 3.5): MRR e pagantes mês a mês, quem virou pagante e quem saiu (churn),
 * e cada turma de cadastro (ativação em 7 dias, conversão em pagante, quantas seguem em uso depois de 1, 2 e
 * 3 meses). Lê só o que os produtos enviam ao Core (contas, histórico de mudanças e fechamento do dia).
 *
 * Pagante = MRR maior que zero (o produto já manda 0 para teste/isenta). Em uso = ProductAccount::LIVE.
 * O histórico começa no deploy das métricas: antes disso, só a data de cadastro e a ativação são conhecidas.
 */
class ProductMetrics
{
    public const MONTHS = [6, 12, 24];

    public const RETENTION_MONTHS = [1, 2, 3];

    private Collection $accounts;

    /** @var Collection<string, Collection<int, ProductAccountEvent>> eventos por conta, do mais antigo ao mais novo */
    private Collection $events;

    private Collection $days;

    private ?array $monthly = null;

    private ?array $cohorts = null;

    public function __construct(private ?string $integrationId, private int $months = 12)
    {
        $this->accounts = ProductAccount::query()
            ->when($integrationId, fn ($q, $id) => $q->where('integration_id', $id))
            ->get(['id', 'integration_id', 'status', 'mrr', 'signed_up_at', 'activated_at']);

        $this->events = ProductAccountEvent::query()
            ->when($integrationId, fn ($q, $id) => $q->where('integration_id', $id))
            ->orderBy('occurred_at')->orderBy('id')
            ->get()->groupBy('product_account_id');

        $this->days = ProductMetricDay::query()
            ->when($integrationId, fn ($q, $id) => $q->where('integration_id', $id))
            ->orderBy('day')->get()->groupBy('integration_id');
    }

    /** Quando o histórico de mudanças começou (null = ainda nenhum). */
    public function historySince(): ?Carbon
    {
        return $this->events->collapse()->min('occurred_at');
    }

    /** Números de agora e da soma das turmas do período. */
    public function summary(): array
    {
        $live = $this->accounts->where('status', '!=', 'removed');
        $cohorts = collect($this->cohorts());
        $thisMonth = collect($this->monthly())->last();
        $signups = $cohorts->sum('signups');
        $decided = $cohorts->sum('decided');

        return [
            'mrr' => round((float) $live->sum('mrr'), 2),
            'paying' => $live->filter(fn ($a) => (float) $a->mrr > 0)->count(),
            'trial' => $live->where('status', 'trial')->count(),
            'signups' => $signups,
            'activation' => self::pct($cohorts->sum('activated_7d'), $signups),
            'conversion' => self::pct($cohorts->sum('paid'), $decided),
            'churn' => $thisMonth['churn'] ?? null,
            'lost_this_month' => $thisMonth['lost'] ?? 0,
            'median_days_to_activate' => self::median($cohorts->pluck('days')->flatten()->all()),
        ];
    }

    /**
     * Uma linha por mês, do mais antigo ao atual.
     *
     * @return list<array{month: Carbon, label: string, signups: int, new_paying: int, lost: int, mrr_lost: float, paying_start: ?int, paying_end: ?int, mrr_end: ?float, churn: ?float}>
     */
    public function monthly(): array
    {
        if ($this->monthly !== null) {
            return $this->monthly;
        }
        $rows = [];
        $changes = $this->events->collapse()->where('baseline', false);

        foreach ($this->monthRange() as $start) {
            $end = $start->copy()->endOfMonth();
            $current = $start->isSameMonth(now());
            $inMonth = $changes->filter(fn ($e) => $e->occurred_at->between($start, $end));
            $lost = $inMonth->filter(fn ($e) => (float) $e->from_mrr > 0 && (float) $e->to_mrr <= 0);

            [$payingEnd, $mrrEnd] = $current
                ? [$this->accounts->where('status', '!=', 'removed')->filter(fn ($a) => (float) $a->mrr > 0)->count(), round((float) $this->accounts->where('status', '!=', 'removed')->sum('mrr'), 2)]
                : $this->closingOf($end);
            [$payingStart] = $this->closingOf($start->copy()->subDay()->endOfDay());

            $rows[] = [
                'month' => $start,
                'label' => ucfirst($start->locale('pt_BR')->translatedFormat('M/Y')),
                'signups' => $this->accounts->filter(fn ($a) => $a->signed_up_at?->between($start, $end))->count(),
                'new_paying' => $inMonth->filter(fn ($e) => (float) $e->from_mrr <= 0 && (float) $e->to_mrr > 0)->count(),
                'lost' => $lost->count(),
                'mrr_lost' => round((float) $lost->sum('from_mrr'), 2),
                'paying_start' => $payingStart,
                'paying_end' => $payingEnd,
                'mrr_end' => $mrrEnd,
                'churn' => $payingStart ? self::pct($lost->count(), $payingStart) : null,
            ];
        }

        return $this->monthly = $rows;
    }

    /**
     * Uma turma por mês de cadastro, da mais nova à mais antiga.
     *
     * @return list<array<string, mixed>>
     */
    public function cohorts(): array
    {
        if ($this->cohorts !== null) {
            return $this->cohorts;
        }
        $rows = [];

        foreach (array_reverse($this->monthRange()) as $start) {
            $end = $start->copy()->endOfMonth();
            $group = $this->accounts->filter(fn ($a) => $a->signed_up_at?->between($start, $end))->values();

            $activated = $group->filter(fn ($a) => $a->activated_at !== null);
            $days = $activated->map(fn ($a) => max(0, (int) floor($a->signed_up_at->diffInHours($a->activated_at, false) / 24)))->values()->all();
            $paid = $group->filter(fn ($a) => $this->everPaid($a));
            // Quem ainda está no teste (e nunca pagou) não decidiu: fica fora da conta da conversão.
            $decided = $group->reject(fn ($a) => $a->status === 'trial' && ! $this->everPaid($a))->count();

            $retention = [];
            foreach (self::RETENTION_MONTHS as $n) {
                $known = $group->map(fn ($a) => $a->signed_up_at->copy()->addMonthsNoOverflow($n)->isFuture() ? 'future' : $this->liveAt($a, $a->signed_up_at->copy()->addMonthsNoOverflow($n)));
                $measurable = $known->filter(fn ($v) => is_bool($v));
                $retention[$n] = $group->isEmpty() || $known->every(fn ($v) => $v === 'future') ? null
                    : ['pct' => self::pct($measurable->filter()->count(), $measurable->count()), 'live' => $measurable->filter()->count(), 'known' => $measurable->count(), 'unknown' => $known->filter(fn ($v) => $v === null)->count()];
            }

            $rows[] = [
                'month' => $start,
                'label' => ucfirst($start->locale('pt_BR')->translatedFormat('M/Y')),
                'signups' => $group->count(),
                'activated_7d' => $activated->filter(fn ($a) => $a->activated_at->lte($a->signed_up_at->copy()->addDays(7)))->count(),
                'activated' => $activated->count(),
                'days' => $days,
                'median_days' => self::median($days),
                'paid' => $paid->count(),
                'decided' => $decided,
                'conversion' => self::pct($paid->count(), $decided),
                'retention' => $retention,
            ];
        }

        return $this->cohorts = $rows;
    }

    /** @return list<Carbon> início de cada mês do período, do mais antigo ao atual */
    private function monthRange(): array
    {
        $first = now()->startOfMonth()->subMonthsNoOverflow($this->months - 1);

        return array_map(fn ($i) => $first->copy()->addMonthsNoOverflow($i), range(0, $this->months - 1));
    }

    /** Pagantes e MRR no fechamento mais recente até a data (soma dos produtos); [null, null] se não há fechamento. */
    private function closingOf(CarbonInterface $at): array
    {
        $day = $at->toDateString();
        $closings = $this->days->map(fn (Collection $rows) => $rows->filter(fn ($r) => $r->day <= $day)->last())->filter();

        return $closings->isEmpty() ? [null, null] : [(int) $closings->sum('paying'), round((float) $closings->sum('mrr'), 2)];
    }

    private function everPaid(ProductAccount $account): bool
    {
        return (float) $account->mrr > 0 || ($this->events[$account->id] ?? collect())->contains(fn ($e) => (float) $e->to_mrr > 0);
    }

    /**
     * A conta estava em uso na data? null = não se sabe (antes do histórico e já tinha saído quando ele começou).
     */
    private function liveAt(ProductAccount $account, CarbonInterface $at): ?bool
    {
        $events = $this->events[$account->id] ?? collect();
        $last = $events->filter(fn ($e) => $e->occurred_at->lte($at))->last();
        if ($last) {
            return in_array($last->to_status, ProductAccount::LIVE, true);
        }

        $first = $events->first();
        if (! $first) {
            return null;
        }

        // Antes do 1º registro. Conta criada depois do início do histórico: nasceu na situação do 1º registro.
        // Conta que já existia: se estava em uso no início do histórico, estava antes também; se já tinha saído, não se sabe quando.
        $live = in_array($first->to_status, ProductAccount::LIVE, true);

        return ! $first->baseline || $live ? $live : null;
    }

    private static function pct(int|float $part, int|float $total): ?float
    {
        return $total > 0 ? round($part / $total * 100, 1) : null;
    }

    private static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
