<?php

namespace App\Finance\Rewards;

use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Categories\Category;
use App\Finance\Goals\Goal;
use App\Finance\Goals\GoalEntry;
use App\Finance\Insights\Indicators;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use App\Platform\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Conquistas da carteira (docs/12 §13, "Conquistas"). Pontua o que melhora o dinheiro de verdade — nunca o uso do
 * app — a partir dos números que já existem (Indicators, metas, faturas, extrato). Roda dentro do contexto da
 * carteira. Mês fechado é avaliado uma vez (marca "avaliado:AAAA-MM"); toda chave é única, então rodar de novo
 * não duplica nada.
 */
class Rewards
{
    /** Pontos para chegar em cada nível. */
    public const LEVELS = [0 => 'Filhote', 200 => 'Raposinha', 600 => 'Raposa esperta', 1500 => 'Raposa sábia', 3000 => 'Raposa lendária'];

    public const MONTH_POSITIVE = 100;

    public const BUDGET_KEPT = 30;

    public const GOAL_ON_TRACK = 50;

    public const INVOICE_ON_TIME = 30;

    public const STATEMENT_REVIEWED = 20;

    public const CHALLENGE_WON = 80;

    public const MAX_ACTIVE = 3;

    /** Conquistas (chave => [nome, pontos, como ganhar]). As de meta batida usam "meta_batida:{id}". */
    public const BADGES = [
        'primeira_meta' => ['🎯 Primeira meta', 20, 'Criar a primeira meta de guardar.'],
        'reserva_1' => ['🛟 1 mês de reserva', 50, 'Ter guardado o gasto de 1 mês na poupança.'],
        'reserva_3' => ['🛡️ 3 meses de reserva', 100, 'Ter guardado o gasto de 3 meses.'],
        'reserva_6' => ['🏰 6 meses de reserva', 150, 'Ter guardado o gasto de 6 meses.'],
        'azul_3' => ['🔥 3 meses no azul', 50, 'Fechar 3 meses seguidos com sobra.'],
        'azul_6' => ['🔥 6 meses no azul', 100, 'Fechar 6 meses seguidos com sobra.'],
        'faturas_6' => ['💳 6 faturas em dia', 50, 'Pagar 6 faturas do cartão até o vencimento.'],
        'desafios_3' => ['🏆 3 desafios vencidos', 50, 'Vencer 3 desafios do mês.'],
        'meta_batida' => ['🥇 Meta batida', 100, 'Juntar todo o valor de uma meta (vale para cada meta).'],
    ];

    public function __construct(private CardInvoices $invoices) {}

    /** Avalia o mês que fechou (se ainda não foi) e as conquistas. Chamado ao abrir as telas e pela rotina diária. */
    public function catchUp(): void
    {
        $last = today()->startOfMonth()->subMonthNoOverflow();
        if (! Reward::query()->where('key', 'avaliado:'.$last->format('Y-m'))->exists()) {
            $this->evaluateMonth($last);
        }
        $this->badges();
    }

    public function evaluateMonth(Carbon $month): void
    {
        $month = $month->copy()->startOfMonth();
        $ym = $month->format('Y-m');
        $name = TransactionFilters::monthName($month);
        $indicators = Indicators::for($month);

        $summary = $indicators->summary();
        if ($summary['income'] > 0 && $summary['saved'] > 0) {
            // No negócio, o "azul" é o lucro do mês.
            $label = \App\Finance\Wallets\Wallet::query()->first()?->isBusiness()
                ? "Mês de {$name} com lucro (".Money::format($summary['saved']).')'
                : "Mês de {$name} no azul (sobrou ".Money::format($summary['saved']).')';
            $this->award("azul:{$ym}", self::MONTH_POSITIVE, $label, $ym);
        }

        foreach ($indicators->budgets() as $b) {
            if ($b['limit'] > 0 && $b['spent'] <= $b['limit']) {
                $this->award("orcamento:{$b['category']->id}:{$ym}", self::BUDGET_KEPT, "Orçamento de {$b['category']->name} respeitado em {$name}", $ym);
            }
        }

        foreach (Goal::query()->whereNull('archived_at')->whereNotNull('deadline')->withSum('entries', 'amount_cents')->get() as $goal) {
            $p = $goal->progress();
            if ($p['on_track'] === true && $goal->created_at?->lt($month->copy()->endOfMonth())) {
                $this->award("meta:{$goal->id}:{$ym}", self::GOAL_ON_TRACK, "Meta {$goal->name} no ritmo em {$name}", $ym);
            }
        }

        // Fatura com vencimento no mês, paga (com transferências até o dia do vencimento).
        foreach (Account::active()->where('kind', Account::CARD)->get() as $card) {
            $invoice = $this->invoices->invoice($card, $ym);
            if ($invoice['charges'] <= 0) {
                continue;
            }
            $paidOnTime = (int) Transaction::query()->done()->where('kind', Transaction::TRANSFER)->where('to_account_id', $card->id)
                ->where('invoice_month', $ym)->where('date', '<=', $invoice['due']->toDateString())->sum('amount_cents');
            if ($paidOnTime >= $invoice['charges']) {
                $this->award("fatura:{$card->id}:{$ym}", self::INVOICE_ON_TIME, "Fatura do {$card->name} de {$name} paga em dia", $ym);
            }
        }

        $lines = StatementLine::query()->whereBetween('date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()]);
        if ((clone $lines)->exists() && ! (clone $lines)->where('status', StatementLine::PENDING)->exists()) {
            $this->award("extrato:{$ym}", self::STATEMENT_REVIEWED, "Extrato de {$name} todo revisado", $ym);
        }

        foreach (Challenge::query()->where('month', $ym)->where('status', Challenge::ACTIVE)->get() as $challenge) {
            $p = $this->progress($challenge);
            $challenge->update(['status' => $p['winning'] ? Challenge::WON : Challenge::LOST, 'result' => $p['value']]);
            if ($p['winning']) {
                $this->award("desafio:{$challenge->id}", self::CHALLENGE_WON, 'Desafio vencido: '.$challenge->label, $ym);
            }
        }

        $this->award("avaliado:{$ym}", 0, "Mês {$ym} avaliado", $ym, Reward::MARKER);
    }

    /** Conquistas que valem a qualquer momento. */
    public function badges(): void
    {
        $goals = Goal::query()->withSum('entries', 'amount_cents')->get();
        if ($goals->isNotEmpty()) {
            $this->badge('primeira_meta');
        }
        foreach ($goals as $goal) {
            if ($goal->target_cents > 0 && $goal->progress()['done']) {
                $this->badge('meta_batida', $goal->id, '🥇 Meta batida: '.$goal->name);
            }
        }

        $months = Indicators::for(today())->reserve()['months'] ?? 0;
        foreach ([1 => 'reserva_1', 3 => 'reserva_3', 6 => 'reserva_6'] as $min => $key) {
            if ($months !== null && $months >= $min) {
                $this->badge($key);
            }
        }

        $streak = $this->positiveStreak();
        if ($streak >= 3) {
            $this->badge('azul_3');
        }
        if ($streak >= 6) {
            $this->badge('azul_6');
        }
        if (Reward::query()->where('key', 'like', 'fatura:%')->count() >= 6) {
            $this->badge('faturas_6');
        }
        if (Challenge::query()->where('status', Challenge::WON)->count() >= 3) {
            $this->badge('desafios_3');
        }
    }

    /** Meses seguidos no azul, contando para trás do último mês fechado. */
    public function positiveStreak(): int
    {
        $keys = Reward::query()->where('key', 'like', 'azul:%')->pluck('key')->flip();
        $month = today()->startOfMonth()->subMonthNoOverflow();
        $count = 0;
        while ($keys->has('azul:'.$month->format('Y-m'))) {
            $count++;
            $month->subMonthNoOverflow();
        }

        return $count;
    }

    public function total(): int
    {
        return (int) Reward::query()->whereIn('kind', [Reward::POINTS, Reward::BADGE])->sum('points');
    }

    /** @return array{name: string, points: int, next: ?string, next_at: ?int, pct: float} */
    public function level(?int $points = null): array
    {
        $points ??= $this->total();
        $name = self::LEVELS[0];
        $from = 0;
        $next = null;
        $nextAt = null;
        foreach (self::LEVELS as $min => $label) {
            if ($points >= $min) {
                $name = $label;
                $from = $min;
            } elseif ($next === null) {
                $next = $label;
                $nextAt = $min;
            }
        }

        return ['name' => $name, 'points' => $points, 'next' => $next, 'next_at' => $nextAt,
            'pct' => $nextAt ? min(1, ($points - $from) / max(1, $nextAt - $from)) : 1.0];
    }

    /**
     * Desafios sugeridos para o mês, a partir dos números da carteira (até 3, sem repetir o que já foi aceito):
     * gastar menos onde mais dá para cortar, guardar o "por mês" da meta e menos gastinhos.
     *
     * @return Collection<int, array{kind: string, category_id: ?string, goal_id: ?string, target_cents: int, target_count: ?int, label: string, why: string}>
     */
    public function suggestions(?Carbon $month = null): Collection
    {
        $month = ($month ?? today())->copy()->startOfMonth();
        $taken = Challenge::query()->where('month', $month->format('Y-m'))->get();
        $out = collect();

        foreach (Indicators::for($month)->cutInHalf()['categories'] as $c) {
            if ($taken->contains('category_id', $c['id'])) {
                continue;
            }
            // 20% a menos do que costuma gastar (a média dos meses anteriores, ou o mês até aqui).
            $target = (int) (floor($c['base'] * 0.8 / 1000) * 1000);
            if ($target >= 2000) {
                $out->push(['kind' => Challenge::CATEGORY, 'category_id' => $c['id'], 'goal_id' => null, 'target_cents' => $target, 'target_count' => null,
                    'label' => "Gastar até ".Money::format($target)." com {$c['name']}",
                    'why' => 'Você costuma gastar '.Money::format($c['base']).' por mês com '.$c['name'].'.']);
            }
        }

        foreach (Goal::query()->whereNull('archived_at')->whereNull('achieved_at')->withSum('entries', 'amount_cents')->get() as $goal) {
            $p = $goal->progress();
            if (! $taken->contains('goal_id', $goal->id) && ($p['per_month'] ?? 0) > 0) {
                $out->push(['kind' => Challenge::GOAL, 'category_id' => null, 'goal_id' => $goal->id, 'target_cents' => $p['per_month'], 'target_count' => null,
                    'label' => 'Guardar '.Money::format($p['per_month'])." na meta {$goal->name}",
                    'why' => 'É o que falta guardar por mês para chegar no prazo.']);
            }
        }

        $small = Indicators::for($month->copy()->subMonthNoOverflow())->smallSpending();
        if (! $taken->contains('kind', Challenge::SMALL) && $small['count'] >= 6) {
            $limit = intdiv($small['count'], 2);
            $out->push(['kind' => Challenge::SMALL, 'category_id' => null, 'goal_id' => null, 'target_cents' => 0, 'target_count' => $limit,
                'label' => "No máximo {$limit} gastinhos (compras até R$ 30)",
                'why' => "No mês passado foram {$small['count']} gastinhos, ".Money::format($small['total']).' no total.']);
        }

        return $out->take(self::MAX_ACTIVE)->values();
    }

    /** Aceita um dos desafios sugeridos (no máximo 3 ativos no mês). */
    public function accept(array $suggestion, ?int $userId): ?Challenge
    {
        $ym = today()->format('Y-m');
        if (Challenge::query()->where('month', $ym)->where('status', Challenge::ACTIVE)->count() >= self::MAX_ACTIVE) {
            return null;
        }

        return Challenge::create([
            'month' => $ym, 'kind' => $suggestion['kind'], 'category_id' => $suggestion['category_id'], 'goal_id' => $suggestion['goal_id'],
            'target_cents' => $suggestion['target_cents'], 'target_count' => $suggestion['target_count'], 'label' => $suggestion['label'],
            'status' => Challenge::ACTIVE, 'accepted_by' => $userId,
        ]);
    }

    /**
     * Onde o desafio está agora (ou no fim do mês dele).
     *
     * @return array{value: int, target: int, pct: float, winning: bool, text: string}
     */
    public function progress(Challenge $challenge): array
    {
        $month = Carbon::createFromFormat('Y-m-d', $challenge->month.'-01')->startOfDay();
        $indicators = Indicators::for($month);

        if ($challenge->kind === Challenge::CATEGORY) {
            $spent = (int) ($indicators->categories()->firstWhere('id', $challenge->category_id)['total'] ?? 0);

            return ['value' => $spent, 'target' => $challenge->target_cents, 'pct' => $challenge->target_cents > 0 ? $spent / $challenge->target_cents : 0,
                'winning' => $spent <= $challenge->target_cents,
                'text' => $spent <= $challenge->target_cents ? 'Gastou '.Money::format($spent).' — ainda dá '.Money::format($challenge->target_cents - $spent) : 'Passou '.Money::format($spent - $challenge->target_cents).' do limite'];
        }

        if ($challenge->kind === Challenge::GOAL) {
            $saved = (int) GoalEntry::query()->where('goal_id', $challenge->goal_id)
                ->whereBetween('date', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])->sum('amount_cents');

            return ['value' => $saved, 'target' => $challenge->target_cents, 'pct' => $challenge->target_cents > 0 ? min(1, max(0, $saved) / $challenge->target_cents) : 0,
                'winning' => $saved >= $challenge->target_cents,
                'text' => $saved >= $challenge->target_cents ? 'Guardou '.Money::format($saved).' — desafio batido!' : 'Guardou '.Money::format(max(0, $saved)).', faltam '.Money::format($challenge->target_cents - max(0, $saved))];
        }

        $count = $indicators->smallSpending()['count'];
        $limit = (int) $challenge->target_count;

        return ['value' => $count, 'target' => $limit, 'pct' => $limit > 0 ? $count / $limit : 1,
            'winning' => $count <= $limit,
            'text' => $count <= $limit ? "{$count} de {$limit} gastinhos — ainda dá ".($limit - $count) : "Passou: {$count} gastinhos (o limite era {$limit})"];
    }

    /**
     * Quem mais ajudou no mês (carteira com mais de uma pessoa): quanto cada um guardou nas metas e quantos
     * lançamentos fez. Sem pontos individuais: a pontuação é da carteira, o ranking só mostra a ajuda de cada um.
     *
     * @return Collection<int, array{name: string, saved: int, entries: int}>
     */
    public function ranking(string $organizationId, ?Carbon $month = null): Collection
    {
        $month = ($month ?? today())->copy()->startOfMonth();
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();
        $members = \App\Platform\Memberships\Membership::active()->where('organization_id', $organizationId)->with('user:id,name')->get();
        if ($members->count() < 2) {
            return collect();
        }
        $saved = GoalEntry::query()->where('amount_cents', '>', 0)->whereBetween('date', [$from, $to])
            ->selectRaw('created_by, sum(amount_cents) as total')->groupBy('created_by')->pluck('total', 'created_by');
        $entries = Transaction::query()->whereBetween('date', [$from, $to])
            ->selectRaw('created_by, count(*) as total')->groupBy('created_by')->pluck('total', 'created_by');

        return $members->map(fn ($m) => [
            'name' => $m->user?->name ?? 'Pessoa', 'saved' => (int) ($saved[(string) $m->user_id] ?? 0), 'entries' => (int) ($entries[(string) $m->user_id] ?? 0),
        ])->sortByDesc(fn ($r) => [$r['saved'], $r['entries']])->values();
    }

    /** Conquistas ganhas e as que faltam (para a tela). */
    public function badgeList(): Collection
    {
        $earned = Reward::query()->where('kind', Reward::BADGE)->get()->keyBy('key');

        return collect(self::BADGES)->except('meta_batida')->map(fn ($b, $key) => [
            'key' => $key, 'name' => $b[0], 'points' => $b[1], 'how' => $b[2], 'earned_at' => $earned->get('badge:'.$key)?->created_at,
        ])->values()->concat($earned->filter(fn ($r) => str_starts_with($r->key, 'badge:meta_batida:'))->map(fn ($r) => [
            'key' => $r->key, 'name' => $r->label, 'points' => $r->points, 'how' => self::BADGES['meta_batida'][2], 'earned_at' => $r->created_at,
        ])->values());
    }

    private function badge(string $key, ?string $suffix = null, ?string $label = null): void
    {
        [$name, $points] = self::BADGES[$key];
        $this->award('badge:'.$key.($suffix ? ':'.$suffix : ''), $points, $label ?? $name, null, Reward::BADGE);
    }

    private function award(string $key, int $points, string $label, ?string $month, string $kind = Reward::POINTS): void
    {
        if (Reward::query()->where('key', $key)->exists()) {
            return;
        }
        try {
            Reward::create(['key' => $key, 'kind' => $kind, 'points' => $points, 'label' => mb_substr($label, 0, 160), 'month' => $month]);
        } catch (UniqueConstraintViolationException) {
            // Outra requisição ganhou a mesma chave ao mesmo tempo: fica a dela.
        }
    }
}
