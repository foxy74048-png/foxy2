<?php

namespace App\Finance\WhatsApp;

use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Categories\Category;
use App\Finance\Insights\Indicators;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use App\Finance\Wallets\Wallet;
use App\Platform\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Resumo semanal pelo WhatsApp (docs/12, seção 8), sem IA: a semana que passou (entrou, saiu, onde mais gastou),
 * como está o mês e o que vence nos próximos 7 dias. Roda no contexto da carteira; null = nada para contar.
 */
class WeeklySummary
{
    public function __construct(private CardInvoices $invoices) {}

    public function for(Wallet $wallet, ?Carbon $today = null): ?string
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $from = $today->copy()->subDays(7);
        $to = $today->copy()->subDay();

        $week = Transaction::query()->done()->whereNull('partner_id')->whereIn('kind', [Transaction::INCOME, Transaction::EXPENSE])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get(['kind', 'amount_cents', 'category_id']);
        $income = (int) $week->where('kind', Transaction::INCOME)->sum('amount_cents');
        $expense = (int) $week->where('kind', Transaction::EXPENSE)->sum('amount_cents');

        // Onde mais gastou na semana, por grupo de categoria.
        $categories = Category::query()->get()->keyBy('id');
        $byGroup = [];
        foreach ($week->where('kind', Transaction::EXPENSE) as $t) {
            $c = $categories->get($t->category_id);
            $name = ($c?->parent_id ? $categories->get($c->parent_id) : $c)?->name ?? 'Sem categoria';
            $byGroup[$name] = ($byGroup[$name] ?? 0) + $t->amount_cents;
        }
        arsort($byGroup);

        // Próximos 7 dias: contas previstas fora do cartão e faturas que vencem.
        $cards = Account::query()->where('kind', Account::CARD)->whereNull('archived_at')->get();
        $upcoming = Transaction::query()->where('status', Transaction::PLANNED)->where('kind', Transaction::EXPENSE)
            ->whereNotIn('account_id', $cards->pluck('id')->all())
            ->whereBetween('date', [$today->toDateString(), $today->copy()->addDays(6)->toDateString()])->orderBy('date')->get();
        $bills = $upcoming->map(fn ($t) => [$t->date, $t->description, (int) $t->amount_cents]);
        foreach ($cards as $card) {
            foreach (array_unique([$today->format('Y-m'), $today->copy()->addDays(6)->format('Y-m')]) as $month) {
                $invoice = $this->invoices->invoice($card, $month);
                if ($invoice['remaining'] > 0 && $invoice['due']->between($today, $today->copy()->addDays(6))) {
                    $bills->push([$invoice['due'], 'Fatura '.$card->name, $invoice['remaining']]);
                }
            }
        }

        if ($week->isEmpty() && $bills->isEmpty()) {
            return null;
        }

        $month = Indicators::for($today->copy()->startOfMonth())->summary();
        $lines = ["🦊 *Sua semana em {$wallet->name}* ({$from->format('d/m')} a {$to->format('d/m')})"];
        $lines[] = 'Entrou: '.Money::format($income).' · Saiu: '.Money::format($expense);
        if ($byGroup !== []) {
            $lines[] = 'Onde mais gastou: '.collect($byGroup)->take(3)->map(fn ($c, $n) => $n.' '.Money::format($c))->implode(', ');
        }
        $lines[] = '';
        $lines[] = '📅 '.ucfirst(TransactionFilters::monthName($today->copy()->startOfMonth())).' até agora: sobrou '.Money::format($month['saved']);
        if ($bills->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '⏰ Vence nos próximos 7 dias:';
            foreach ($bills->sortBy(fn ($b) => $b[0]->toDateString())->take(8) as [$date, $label, $cents]) {
                $lines[] = '• '.$date->format('d/m').' '.$label.' '.Money::format($cents);
            }
        }
        $lines[] = '';
        $lines[] = 'Calendário completo: https://'.config('foxy.hosts.financas').'/app/calendario';

        return implode("\n", $lines);
    }
}
