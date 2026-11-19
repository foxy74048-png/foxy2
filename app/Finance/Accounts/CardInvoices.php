<?php

namespace App\Finance\Accounts;

use App\Finance\Transactions\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Faturas de um cartão, calculadas dos lançamentos (sem tabela própria, docs/12 A-F3). Uma fatura = o mês do
 * vencimento ("2026-12"). Compras e estornos do cartão caem na fatura pelo invoice_month; pagamentos são
 * transferências de uma conta para o cartão, ligadas à fatura que pagam.
 */
class CardInvoices
{
    public const STATUSES = [
        'aberta' => 'Aberta', 'fechada' => 'Fechada, a pagar', 'paga' => 'Paga', 'parcial' => 'Paga em parte',
        'atrasada' => 'Vencida', 'futura' => 'Futura', 'zerada' => 'Sem gastos',
    ];

    /**
     * @return array{month: string, closing: Carbon, due: Carbon, charges: int, payments: int, remaining: int, status: string, items: Collection}
     */
    public function invoice(Account $card, string $month, bool $withItems = false): array
    {
        $rows = Transaction::query()->done()->where('invoice_month', $month)
            ->where(fn ($q) => $q->where('account_id', $card->id)->orWhere('to_account_id', $card->id))
            ->when($withItems, fn ($q) => $q->with(['category.parent', 'account']))
            ->orderBy('date')->orderBy('created_at')->get();

        [$charges, $payments] = $this->sum($rows, $card);

        return $this->summary($card, $month, $charges, $payments) + [
            'items' => $withItems ? $rows : collect(),
        ];
    }

    /**
     * A fatura aberta, as que fecharam e ainda não foram pagas (até 6 meses para trás) e as futuras que já têm
     * parcelas (até 12 meses), em ordem.
     *
     * @return Collection<int, array>
     */
    public function overview(Account $card): Collection
    {
        $open = $card->invoiceMonthFor(today());
        $from = Account::addMonths($open, -6);
        $to = Account::addMonths($open, 12);

        $rows = Transaction::query()->done()->whereBetween('invoice_month', [$from, $to])
            ->where(fn ($q) => $q->where('account_id', $card->id)->orWhere('to_account_id', $card->id))
            ->get()->groupBy('invoice_month');

        $out = collect();
        for ($month = $from; $month <= $to; $month = Account::addMonths($month, 1)) {
            [$charges, $payments] = $this->sum($rows->get($month, collect()), $card);
            $invoice = $this->summary($card, $month, $charges, $payments);
            // Passadas só aparecem se ficou algo para pagar; futuras, só se já têm parcela.
            if ($month < $open && $invoice['remaining'] <= 0 && $month !== Account::addMonths($open, -1)) {
                continue;
            }
            if ($month > $open && $charges === 0) {
                continue;
            }
            $out->push($invoice);
        }

        return $out;
    }

    /** Tudo o que ainda falta pagar no cartão, inclusive as parcelas futuras (é o que o banco desconta do limite). */
    public function outstanding(Account $card): int
    {
        $rows = Transaction::query()->done()->where(fn ($q) => $q->where('account_id', $card->id)->orWhere('to_account_id', $card->id))->get();
        [$charges, $payments] = $this->sum($rows, $card);

        return max(0, $charges - $payments);
    }

    /** Já comprometido com o cartão nos meses depois da fatura aberta (parcelas), por mês. */
    public function committed(Account $card): array
    {
        $open = $card->invoiceMonthFor(today());

        return Transaction::query()->done()->where('account_id', $card->id)->where('invoice_month', '>', $open)
            ->selectRaw("invoice_month, sum(case when kind = 'recebimento' then -amount_cents else amount_cents end) as total")
            ->groupBy('invoice_month')->orderBy('invoice_month')->pluck('total', 'invoice_month')
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)->all();
    }

    /** @return array{0: int, 1: int} [gastos − estornos, pagamentos] */
    private function sum(Collection $rows, Account $card): array
    {
        $charges = 0;
        $payments = 0;
        foreach ($rows as $t) {
            if ($t->kind === Transaction::TRANSFER && $t->to_account_id === $card->id) {
                $payments += $t->amount_cents;
            } elseif ($t->account_id === $card->id) {
                $charges += $t->kind === Transaction::INCOME ? -$t->amount_cents : $t->amount_cents;
            }
        }

        return [$charges, $payments];
    }

    private function summary(Account $card, string $month, int $charges, int $payments): array
    {
        $closing = $card->closingDate($month);
        $due = $card->dueDate($month);
        $remaining = $charges - $payments;
        $today = today();

        $status = match (true) {
            $charges <= 0 && $payments <= 0 => $closing->gt($today) ? ($month === $card->invoiceMonthFor($today) ? 'aberta' : 'futura') : 'zerada',
            $closing->gt($today) => $month === $card->invoiceMonthFor($today) ? 'aberta' : 'futura',
            $remaining <= 0 => 'paga',
            $due->lt($today) => 'atrasada',
            $payments > 0 => 'parcial',
            default => 'fechada',
        };

        return ['month' => $month, 'closing' => $closing, 'due' => $due, 'charges' => $charges, 'payments' => $payments, 'remaining' => max(0, $remaining), 'status' => $status];
    }
}
