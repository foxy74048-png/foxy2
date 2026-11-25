<?php

namespace App\Finance\Http;

use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Insights\Export;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Calendário do mês (fatia 6, "calendário"): em que dia sai e entra cada coisa — contas e recebimentos
 * (feitos e previstos), parcelas e assinaturas fora do cartão, e o vencimento de cada fatura com o que falta
 * pagar. As compras no cartão aparecem dentro da fatura, não no dia da compra (é quando o dinheiro sai).
 */
class CalendarController
{
    public function __construct(private CardInvoices $invoices) {}

    public function index(Request $request): View|Response
    {
        $request->validate(['mes' => ['nullable', 'date_format:Y-m']]);
        $month = Carbon::createFromFormat('Y-m-d', ($request->query('mes') ?: today()->format('Y-m')).'-01')->startOfDay();
        $items = $this->items($month);

        $pending = $items->where('done', false);
        $totals = [
            'to_pay' => (int) $pending->where('sign', -1)->sum('amount'),
            'to_receive' => (int) $pending->where('sign', 1)->sum('amount'),
            'paid' => (int) $items->where('done', true)->where('sign', -1)->sum('amount'),
            'received' => (int) $items->where('done', true)->where('sign', 1)->sum('amount'),
        ];

        if ($format = Export::requested($request)) {
            return (new Export('Calendário de '.TransactionFilters::monthName($month)))
                ->section('Resumo', ['Falta pagar' => 'money', 'Falta receber' => 'money', 'Já saiu' => 'money', 'Já entrou' => 'money'],
                    [[$totals['to_pay'], $totals['to_receive'], $totals['paid'], $totals['received']]])
                ->section('Dia a dia', ['Dia' => 'date', 'O quê' => 'text', 'Conta' => 'text', 'Valor' => 'money', 'Situação' => 'text'],
                    $items->map(fn ($i) => [$i['date'], $i['label'], $i['account'], $i['sign'] * $i['amount'], $i['status']]))
                ->download($format);
        }

        return view('finance.calendar', [
            'month' => $month,
            'monthName' => TransactionFilters::monthName($month),
            'prev' => $month->copy()->subMonthNoOverflow()->format('Y-m'),
            'next' => $month->copy()->addMonthNoOverflow()->format('Y-m'),
            'byDay' => $items->groupBy(fn ($i) => $i['date']->toDateString()),
            'totals' => $totals,
            'weeks' => $this->weeks($month),
        ]);
    }

    /** @return Collection<int, array{date: Carbon, label: string, account: ?string, amount: int, sign: int, done: bool, status: string, kind: string}> */
    private function items(Carbon $month): Collection
    {
        $from = $month->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();
        $cards = Account::query()->where('kind', Account::CARD)->whereNull('archived_at')->get();
        $cardIds = $cards->pluck('id')->all();

        $rows = Transaction::query()->whereBetween('date', [$from, $to])->whereNotIn('account_id', $cardIds)
            ->where(fn ($q) => $q->whereNull('to_account_id')->orWhereNotIn('to_account_id', $cardIds)) // pagar a fatura já aparece na fatura
            ->with(['account', 'toAccount'])->orderBy('date')->get()
            ->map(fn (Transaction $t) => [
                'date' => $t->date->copy()->startOfDay(),
                'label' => $t->description.($t->seriesLabel() ? ' ('.$t->seriesLabel().')' : '').($t->isTransfer() ? ' → '.$t->toAccount?->name : ''),
                'account' => $t->account?->name,
                'amount' => (int) $t->amount_cents,
                'sign' => match ($t->kind) { Transaction::INCOME => 1, Transaction::EXPENSE => -1, default => 0 },
                'done' => $t->status === Transaction::DONE,
                'status' => Transaction::STATUSES[$t->status] ?? $t->status,
                'kind' => $t->isTransfer() ? 'transfer' : $t->kind,
            ]);

        // Faturas que vencem neste mês (o mês da fatura é o do vencimento).
        foreach ($cards as $card) {
            $invoice = $this->invoices->invoice($card, $month->format('Y-m'));
            if ($invoice['charges'] <= 0) {
                continue;
            }
            $paid = $invoice['remaining'] <= 0;
            $rows->push([
                'date' => $invoice['due']->copy()->startOfDay(),
                'label' => 'Fatura '.$card->name.($paid ? ' (paga)' : ($invoice['payments'] > 0 ? ' (falta parte)' : '')),
                'account' => $card->name,
                'amount' => $paid ? $invoice['charges'] : $invoice['remaining'],
                'sign' => -1,
                'done' => $paid,
                'status' => CardInvoices::STATUSES[$invoice['status']] ?? $invoice['status'],
                'kind' => 'invoice',
            ]);
        }

        return $rows->sortBy(fn ($i) => [$i['date']->toDateString(), $i['kind'] === 'invoice' ? 0 : 1])->values();
    }

    /** Semanas do mês, de segunda a domingo; dias de fora ficam null. @return list<list<?Carbon>> */
    private function weeks(Carbon $month): array
    {
        $day = $month->copy()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $weeks = [];
        while ($day <= $end) {
            $week = [];
            for ($i = 0; $i < 7; $i++, $day->addDay()) {
                $week[] = $day->month === $month->month ? $day->copy() : null;
            }
            $weeks[] = $week;
        }

        return $weeks;
    }
}
