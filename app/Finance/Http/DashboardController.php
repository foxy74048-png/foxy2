<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Memberships\Membership;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Início da carteira: o mês (entrou, saiu, sobrou), o saldo das contas, o que está previsto para os próximos
 * dias e onde mais se gastou. Os painéis completos (6/12 meses, "o que mudou", orçamento) chegam na fatia 6.
 */
class DashboardController
{
    public function index(Request $request, TransactionService $service): View
    {
        $membership = $request->attributes->get('finance.membership');
        $service->confirmDueCardCharges();
        $from = today()->startOfMonth()->toDateString();
        $to = today()->endOfMonth()->toDateString();
        $month = fn () => Transaction::query()->done()->whereBetween('date', [$from, $to]);

        $accounts = Account::active()->get();
        $balances = $service->balances($accounts);

        // Onde mais gastou no mês: soma por grupo (a subcategoria conta no grupo dela).
        $categories = Category::query()->get()->keyBy('id');
        $byCategory = $month()->where('kind', Transaction::EXPENSE)->selectRaw('category_id, sum(amount_cents) as total')->groupBy('category_id')->pluck('total', 'category_id');
        $top = [];
        foreach ($byCategory as $id => $total) {
            $category = $categories->get($id);
            $root = $category?->parent_id ? $categories->get($category->parent_id) : $category;
            $key = $root?->id ?? '';
            $top[$key] = ['id' => $root?->id, 'name' => $root?->name ?? 'Sem categoria', 'total' => ($top[$key]['total'] ?? 0) + (int) $total];
        }
        usort($top, fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('finance.dashboard', [
            'people' => Membership::active()->where('organization_id', $membership->organization_id)->count(),
            'canManagePeople' => Access::allows($membership, 'finance.people.manage'),
            'canManage' => Access::allows($membership, 'finance.transactions.manage'),
            'income' => (int) $month()->where('kind', Transaction::INCOME)->sum('amount_cents'),
            'expense' => (int) $month()->where('kind', Transaction::EXPENSE)->sum('amount_cents'),
            'balance' => $accounts->reject->isCard()->sum(fn ($a) => $balances[$a->id]),
            'hasAccounts' => $accounts->isNotEmpty(),
            'upcoming' => Transaction::query()->where('status', Transaction::PLANNED)->where('date', '<=', today()->addDays(7)->toDateString())
                ->with('account')->orderBy('date')->limit(8)->get(),
            'top' => array_slice($top, 0, 5),
            // Indicadores (fatia 6): o que a pessoa precisa ver logo na entrada.
            'indicators' => $indicators = \App\Finance\Insights\Indicators::for(today()),
            'savingsRate' => $indicators->summary()['savings_rate'],
            'pace' => $indicators->pace(),
            'budgetAlerts' => $indicators->budgets()->where('status', '!=', 'ok')->values(),
            'canSeeInsights' => Access::allows($membership, 'finance.reports.view'),
            // Quem me deve (6c): totais.
            'debts' => ($debtBalances = \App\Finance\Debts\Contact::query()->withSum('entries', 'amount_cents')->get()->map->balanceCents())->isEmpty() ? null
                : ['owed' => $debtBalances->filter(fn ($b) => $b > 0)->sum(), 'owe' => -$debtBalances->filter(fn ($b) => $b < 0)->sum()],
            // Metas em andamento (6b), as 3 com prazo mais perto.
            'goals' => \App\Finance\Goals\Goal::query()->whereNull('archived_at')->whereNull('achieved_at')->withSum('entries', 'amount_cents')
                ->orderByRaw('deadline is null')->orderBy('deadline')->limit(3)->get(),
            // Conquistas: nível e o primeiro desafio ativo do mês.
            'rewardLevel' => ($rewards = app(\App\Finance\Rewards\Rewards::class))->level(),
            'rewardChallenge' => ($challenge = \App\Finance\Rewards\Challenge::query()->where('month', today()->format('Y-m'))->where('status', 'ativo')->oldest()->first())
                ? ['challenge' => $challenge, 'progress' => $rewards->progress($challenge)] : null,
            // Faturas que fecharam e falta pagar (aviso de vencimento).
            'invoicesToPay' => $accounts->filter->isCard()->flatMap(fn (Account $card) => app(CardInvoices::class)->overview($card)
                ->filter(fn ($i) => in_array($i['status'], ['fechada', 'parcial', 'atrasada'], true))
                ->map(fn ($i) => $i + ['card' => $card]))->sortBy(fn ($i) => $i['due'])->values(),
        ]);
    }
}
