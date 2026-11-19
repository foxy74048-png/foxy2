<?php

namespace App\Finance\Insights;

use App\Finance\Accounts\Account;
use App\Finance\Budgets\Budget;
use App\Finance\Categories\Categorizer;
use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Indicadores para ver onde vai o dinheiro e economizar (docs/11 §4.1, fatia 6). Tudo calculado aqui, sem IA:
 * a tela mostra os números e a raposa (fatia 8) só os transforma em frases. Os mesmos números servem à tela,
 * ao Início e à exportação. Só lançamentos feitos (previsto não conta), sem transferências.
 */
class Indicators
{
    /** Compra até este valor entra em "gastinhos". */
    public const SMALL_CENTS = 3000;

    /** Grupos que não entram no "se cortar pela metade" (o que é difícil cortar). */
    public const ESSENTIAL = ['moradia', 'mercado', 'saude', 'educacao', 'impostos', 'taxas', 'filhos', 'transporte',
        'mercadoria', 'material', 'aluguel_negocio', 'contas_negocio', 'impostos_negocio', 'taxas_negocio', 'equipe'];

    private Collection $rows;

    private Collection $categories;

    public function __construct(private Carbon $month)
    {
        $this->month = $month->copy()->startOfMonth();
        $this->categories = Category::query()->get()->keyBy('id');
        // Um ano para trás é o que todos os indicadores usam (média de 3 meses, assinaturas, evolução).
        // Movimentos de sócio (pró-labore, retirada, aporte) não são gasto nem renda do negócio.
        $this->rows = Transaction::query()->done()->whereNull('partner_id')->whereIn('kind', [Transaction::EXPENSE, Transaction::INCOME])
            ->whereBetween('date', [$this->month->copy()->subMonthsNoOverflow(11)->toDateString(), $this->month->copy()->endOfMonth()->toDateString()])
            ->get(['id', 'kind', 'amount_cents', 'date', 'description', 'category_id', 'series_kind', 'account_id']);
    }

    public static function for(Carbon $month): self
    {
        return new self($month);
    }

    /** Entrou, saiu, sobrou e quanto da renda foi guardado — no mês e na média dos 3 anteriores. */
    public function summary(): array
    {
        [$income, $expense] = $this->totals($this->month);
        $previous = collect(range(1, 3))->map(fn ($i) => $this->totals($this->month->copy()->subMonthsNoOverflow($i)));
        $prevIncome = $previous->sum(fn ($t) => $t[0]);
        $prevExpense = $previous->sum(fn ($t) => $t[1]);

        return [
            'income' => $income,
            'expense' => $expense,
            'saved' => $income - $expense,
            'savings_rate' => $income > 0 ? ($income - $expense) / $income : null,
            'avg_savings_rate' => $prevIncome > 0 ? ($prevIncome - $prevExpense) / $prevIncome : null,
            'avg_expense' => (int) round($prevExpense / 3),
        ];
    }

    /**
     * "No ritmo atual, o mês fecha em R$ X": só no mês corrente. Gasto por dia até hoje × dias do mês; e o que já
     * está previsto para o resto do mês (contas lançadas).
     */
    public function pace(): ?array
    {
        $today = today();
        if (! $this->month->isSameMonth($today)) {
            return null;
        }
        [, $expense] = $this->totals($this->month);
        $days = $this->month->daysInMonth;
        $projected = (int) round($expense / $today->day * $days);
        $planned = (int) Transaction::query()->where('status', Transaction::PLANNED)->where('kind', Transaction::EXPENSE)
            ->whereBetween('date', [$today->toDateString(), $this->month->copy()->endOfMonth()->toDateString()])->sum('amount_cents');

        return ['spent' => $expense, 'projected' => $projected, 'planned' => $planned, 'days_left' => $days - $today->day, 'per_day' => (int) round($expense / $today->day)];
    }

    /**
     * Gasto por grupo de categoria: no mês, a média dos 3 meses anteriores e a diferença. A subcategoria soma no grupo.
     *
     * @return Collection<int, array{id: ?string, name: string, key: ?string, total: int, avg: int, delta: int}>
     */
    public function categories(): Collection
    {
        $now = $this->byRoot($this->month, $this->month->copy()->endOfMonth());
        $before = $this->byRoot($this->month->copy()->subMonthsNoOverflow(3), $this->month->copy()->subDay());
        $ids = collect(array_keys($now))->merge(array_keys($before))->unique();

        return $ids->map(function ($id) use ($now, $before) {
            $root = $id === '' ? null : $this->categories->get($id);
            $avg = (int) round(($before[$id] ?? 0) / 3);

            return ['id' => $root?->id, 'name' => $root?->name ?? 'Sem categoria', 'key' => $root?->system_key, 'total' => $now[$id] ?? 0, 'avg' => $avg, 'delta' => ($now[$id] ?? 0) - $avg];
        })->sortByDesc('total')->values();
    }

    /** Há lançamentos em algum dos 3 meses antes do escolhido? Sem isso, não dá para comparar (tudo seria "novo"). */
    public function hasHistory(): bool
    {
        $from = $this->month->copy()->subMonthsNoOverflow(3);

        return $this->rows->contains(fn ($t) => $t->date->gte($from) && $t->date->lt($this->month));
    }

    /** O que mais subiu em relação à média dos 3 meses anteriores (pelo menos R$ 30 e 20% a mais). */
    public function rises(): Collection
    {
        if (! $this->hasHistory()) {
            return collect();
        }

        return $this->categories()
            ->filter(fn ($c) => $c['delta'] >= 3000 && ($c['avg'] === 0 ? $c['total'] >= 5000 : $c['delta'] / $c['avg'] >= 0.2))
            ->sortByDesc('delta')->take(3)->values();
    }

    /** Onde mais gastou no mês, por lugar (a loja, pelas palavras que a identificam). */
    public function places(int $limit = 5): Collection
    {
        return $this->expensesIn($this->month, $this->month->copy()->endOfMonth())
            ->groupBy(fn (Transaction $t) => Categorizer::defaultPattern($t->description))
            ->map(fn (Collection $g, $key) => ['label' => $this->labelFor($g), 'total' => $g->sum('amount_cents'), 'count' => $g->count()])
            ->sortByDesc('total')->take($limit)->values();
    }

    /**
     * Gastinhos: compras pequenas (até R$ 30) e frequentes. No mês, nos últimos 3 meses e quanto dá por ano nesse
     * ritmo, com os lugares onde mais acontecem.
     */
    public function smallSpending(): array
    {
        $small = fn (Collection $rows) => $rows->filter(fn (Transaction $t) => $t->amount_cents <= self::SMALL_CENTS);
        $month = $small($this->expensesIn($this->month, $this->month->copy()->endOfMonth()));
        $last3 = $small($this->expensesIn($this->month->copy()->subMonthsNoOverflow(2), $this->month->copy()->endOfMonth()));
        $months = $this->monthsWithData(3);

        return [
            'count' => $month->count(),
            'total' => $month->sum('amount_cents'),
            'per_year' => $months > 0 ? (int) round($last3->sum('amount_cents') / $months * 12) : 0,
            'top' => $month->groupBy(fn (Transaction $t) => Categorizer::defaultPattern($t->description))
                ->map(fn (Collection $g) => ['label' => $this->labelFor($g), 'total' => $g->sum('amount_cents'), 'count' => $g->count()])
                ->sortByDesc('total')->take(3)->values(),
        ];
    }

    /**
     * Assinaturas: lançadas como "todo mês", ou o mesmo lugar com valor parecido (±15%) em pelo menos 3 dos
     * últimos 4 meses. Custo por mês (o valor mais comum) e por ano. Contas do essencial (aluguel, luz, escola,
     * plano de saúde...) ficam de fora: a ideia é achar o que dá para cancelar.
     */
    public function subscriptions(): Collection
    {
        $from = $this->month->copy()->subMonthsNoOverflow(3);
        $rows = $this->expensesIn($from, $this->month->copy()->endOfMonth())
            ->reject(fn (Transaction $t) => in_array($this->rootOf($t->category_id)?->system_key, self::ESSENTIAL, true));

        return $rows->groupBy(fn (Transaction $t) => Categorizer::defaultPattern($t->description))
            ->map(function (Collection $g) {
                $months = $g->groupBy(fn ($t) => $t->date->format('Y-m'));
                $amounts = $g->pluck('amount_cents')->sort()->values();
                $median = $amounts[intdiv($amounts->count(), 2)];
                $similar = $g->every(fn ($t) => abs($t->amount_cents - $median) <= $median * 0.15);
                $isSeries = $g->contains(fn ($t) => $t->series_kind === 'mensal');
                // Uma por mês (não é "gastinho" que se repete várias vezes no mês).
                $oncePerMonth = $months->every(fn ($m) => $m->count() === 1);

                return ($isSeries || ($months->count() >= 3 && $similar && $oncePerMonth))
                    ? ['label' => $this->labelFor($g), 'monthly' => $median, 'yearly' => $median * 12, 'category' => $this->categories->get($g->last()->category_id)?->name]
                    : null;
            })->filter()->sortByDesc('monthly')->values();
    }

    /**
     * Reserva de emergência: quanto está guardado (saldo das poupanças) cobre quantos meses do seu gasto médio.
     */
    public function reserve(): array
    {
        $savings = Account::active()->where('kind', 'poupanca')->get();
        $saved = $savings->isEmpty() ? 0 : array_sum(app(TransactionService::class)->balances($savings));
        $avg = $this->summary()['avg_expense'] ?: $this->totals($this->month)[1];

        return ['has_savings_account' => $savings->isNotEmpty(), 'saved' => $saved, 'avg_expense' => $avg, 'months' => $avg > 0 ? $saved / $avg : null];
    }

    /**
     * "Se cortar pela metade": os 3 grupos que dá para cortar (fora moradia, mercado, saúde...) onde você mais
     * gasta, e quanto guardaria por ano gastando a metade.
     */
    public function cutInHalf(): array
    {
        $options = $this->categories()->filter(fn ($c) => $c['id'] && ! in_array($c['key'], self::ESSENTIAL, true))
            ->map(fn ($c) => $c + ['base' => max($c['avg'], $c['total'])])
            ->filter(fn ($c) => $c['base'] >= 3000)->sortByDesc('base')->take(3)->values();

        return [
            'categories' => $options,
            'per_year' => (int) round($options->sum('base') / 2 * 12),
        ];
    }

    /** Entrou e saiu mês a mês (os últimos $months, terminando no mês escolhido). */
    public function evolution(int $months = 6): Collection
    {
        $names = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

        return collect(range($months - 1, 0))->map(function ($i) use ($names) {
            $m = $this->month->copy()->subMonthsNoOverflow($i);
            [$income, $expense] = $this->totals($m);

            return ['month' => $m->format('Y-m'), 'label' => $names[$m->month - 1], 'full' => $names[$m->month - 1].'/'.$m->format('Y'), 'income' => $income, 'expense' => $expense, 'saved' => $income - $expense];
        });
    }

    /**
     * Orçamento do mês: cada limite (o do mês vence o de todo mês da mesma categoria), o quanto já foi e a situação.
     *
     * @return Collection<int, array{budget: Budget, category: Category, limit: int, spent: int, pct: float, status: string}>
     */
    public function budgets(): Collection
    {
        $month = $this->month->format('Y-m');
        $budgets = Budget::query()->with('category')->where(fn ($q) => $q->whereNull('month')->orWhere('month', $month))->get()
            ->filter(fn (Budget $b) => $b->category !== null)
            ->sortBy(fn (Budget $b) => $b->month === null ? 1 : 0)->unique('category_id');
        $expenses = $this->expensesIn($this->month, $this->month->copy()->endOfMonth());

        return $budgets->map(function (Budget $b) use ($expenses) {
            $ids = $this->categories->filter(fn ($c) => $c->id === $b->category_id || $c->parent_id === $b->category_id)->keys()->all();
            $spent = $expenses->whereIn('category_id', $ids)->sum('amount_cents');
            $pct = $b->amount_cents > 0 ? $spent / $b->amount_cents : 0;

            return ['budget' => $b, 'category' => $b->category, 'limit' => $b->amount_cents, 'spent' => $spent, 'pct' => $pct,
                'status' => $pct >= 1 ? 'estourou' : ($pct >= Budget::WARN_AT ? 'perto' : 'ok')];
        })->sortByDesc('pct')->values();
    }

    /** @return array{0: int, 1: int} [entrou, saiu] no mês */
    private function totals(Carbon $month): array
    {
        $rows = $this->rows->filter(fn ($t) => $t->date->isSameMonth($month));

        return [$rows->where('kind', Transaction::INCOME)->sum('amount_cents'), $rows->where('kind', Transaction::EXPENSE)->sum('amount_cents')];
    }

    private function expensesIn(Carbon $from, Carbon $to): Collection
    {
        return $this->rows->filter(fn ($t) => $t->kind === Transaction::EXPENSE && $t->date->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));
    }

    private function rootOf(?string $categoryId): ?Category
    {
        $category = $this->categories->get($categoryId);

        return $category?->parent_id ? $this->categories->get($category->parent_id) : $category;
    }

    /** @return array<string, int> id do grupo ('' = sem categoria) => gasto */
    private function byRoot(Carbon $from, Carbon $to): array
    {
        $out = [];
        foreach ($this->expensesIn($from, $to) as $t) {
            $root = $this->rootOf($t->category_id);
            $out[$root?->id ?? ''] = ($out[$root?->id ?? ''] ?? 0) + $t->amount_cents;
        }

        return $out;
    }

    /** Quantos dos últimos $n meses (até o escolhido) têm algum lançamento — para não dividir por mês vazio. */
    private function monthsWithData(int $n): int
    {
        return collect(range(0, $n - 1))->filter(fn ($i) => $this->rows->contains(fn ($t) => $t->date->isSameMonth($this->month->copy()->subMonthsNoOverflow($i))))->count();
    }

    /** Nome para mostrar de um grupo de lançamentos do mesmo lugar: a descrição mais recente, curta. */
    private function labelFor(Collection $group): string
    {
        return \Illuminate\Support\Str::limit($group->sortByDesc('date')->first()->description, 40);
    }
}
