<?php

namespace App\Finance\Transactions;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Statements\StatementLine;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Única porta para criar, mudar e apagar lançamentos (tela, importação, WhatsApp e sócios passam por aqui).
 * Tudo manual: a Foxy não movimenta dinheiro, só registra e explica.
 *
 * Regras de situação: no cartão o lançamento é sempre "feito" (a compra já aconteceu; a data só diz em que
 * fatura cai); nas outras contas, data futura = "previsto" até a pessoa confirmar.
 */
class TransactionService
{
    public const MAX_INSTALLMENTS = 48;

    public const MAX_MONTHS = 36;

    public const MODES = ['unica' => 'Uma vez', 'parcelas' => 'Parcelado (divide o valor)', 'mensal' => 'Todo mês (mesmo valor)'];

    /**
     * @param  array{kind: string, amount_cents: int, date: string, description: string, account_id: string, to_account_id?: ?string,
     *     category_id?: ?string, notes?: ?string, mode?: string, count?: int, source?: string}  $data
     * @return Collection<int, Transaction>
     */
    public function create(array $data): Collection
    {
        [$account, $toAccount, $category] = $this->check($data, requireActive: true);
        $mode = $data['mode'] ?? 'unica';
        $mode = $data['kind'] === Transaction::TRANSFER && $mode === 'parcelas' ? 'unica' : $mode;
        $count = match ($mode) {
            'parcelas' => max(2, min(self::MAX_INSTALLMENTS, (int) ($data['count'] ?? 2))),
            'mensal' => max(2, min(self::MAX_MONTHS, (int) ($data['count'] ?? 12))),
            default => 1,
        };
        $total = (int) $data['amount_cents'];
        $each = $mode === 'parcelas' ? intdiv($total, $count) : $total;
        $firstExtra = $mode === 'parcelas' ? $total - $each * $count : 0;
        $series = $count > 1 ? strtolower((string) Str::ulid()) : null;
        $first = Carbon::parse($data['date'])->startOfDay();
        $firstInvoice = $account->invoiceMonthFor($first);

        return DB::transaction(function () use ($data, $account, $toAccount, $category, $mode, $count, $each, $firstExtra, $series, $first, $firstInvoice) {
            $created = collect();
            for ($i = 1; $i <= $count; $i++) {
                $date = $first->copy()->addMonthsNoOverflow($i - 1);
                $created->push(Transaction::create([
                    'account_id' => $account->id,
                    'to_account_id' => $toAccount?->id,
                    'kind' => $data['kind'],
                    'amount_cents' => $each + ($i === 1 ? $firstExtra : 0),
                    'date' => $date->toDateString(),
                    'description' => Str::limit(trim($data['description']), 160, ''),
                    'category_id' => $category?->id,
                    'notes' => $data['notes'] ?? null,
                    'status' => $this->statusFor($account, $date),
                    // Parcela no cartão: uma fatura depois da outra, a partir da fatura da compra.
                    'invoice_month' => $firstInvoice ? ($mode === 'parcelas' ? Account::addMonths($firstInvoice, $i - 1) : $account->invoiceMonthFor($date)) : null,
                    'series_id' => $series,
                    'series_kind' => $series ? $mode : null,
                    'installment' => $mode === 'parcelas' ? $i : null,
                    'installments' => $mode === 'parcelas' ? $count : null,
                    'source' => $data['source'] ?? 'manual',
                    'created_by' => $this->userId(),
                    // Veio de uma linha do extrato (só no primeiro, se fosse série).
                    'statement_line_id' => $i === 1 ? ($data['statement_line_id'] ?? null) : null,
                ]));
            }
            Audit::record('finance.transaction.created', subject: $created->first(), metadata: ['mode' => $mode, 'count' => $count, 'kind' => $data['kind']]);

            return $created;
        });
    }

    /**
     * Muda um lançamento. Com $following (parcelas e repetições), descrição, categoria, conta, valor e
     * observação valem também para os próximos da mesma série; a data muda só neste.
     */
    public function update(Transaction $transaction, array $data, bool $following = false): int
    {
        $data['kind'] = $transaction->kind;
        [$account, $toAccount, $category] = $this->check($data, requireActive: false);

        return DB::transaction(function () use ($transaction, $data, $following, $account, $toAccount, $category) {
            $shared = [
                'account_id' => $account->id,
                'to_account_id' => $toAccount?->id,
                'description' => Str::limit(trim($data['description']), 160, ''),
                'category_id' => $category?->id,
                'amount_cents' => (int) $data['amount_cents'],
            ];
            $date = Carbon::parse($data['date'])->startOfDay();
            $originalDate = $transaction->date->toDateString();
            $accountChanged = $account->id !== $transaction->account_id;

            $transaction->fill($shared + [
                'date' => $date->toDateString(),
                'notes' => $data['notes'] ?? null,
                'invoice_month' => $this->invoiceFor($transaction, $account, $date, $accountChanged),
            ]);
            if ($account->isCard()) {
                $transaction->status = Transaction::DONE;
            }
            $transaction->save();
            $changed = 1;

            if ($following && $transaction->series_id) {
                $next = Transaction::where('series_id', $transaction->series_id)->whereKeyNot($transaction->id)
                    ->where('date', '>', $originalDate)->get();
                foreach ($next as $other) {
                    $other->fill($shared + ['notes' => $data['notes'] ?? null, 'invoice_month' => $this->invoiceFor($other, $account, $other->date, $accountChanged)]);
                    if ($account->isCard()) {
                        $other->status = Transaction::DONE;
                    }
                    $other->save();
                    $changed++;
                }
            }
            Audit::record('finance.transaction.updated', subject: $transaction, metadata: ['count' => $changed]);

            return $changed;
        });
    }

    /** Apaga; com $following, também os próximos da mesma série. */
    public function delete(Transaction $transaction, bool $following = false): int
    {
        return DB::transaction(function () use ($transaction, $following) {
            $query = $following && $transaction->series_id
                ? Transaction::where('series_id', $transaction->series_id)->where('date', '>=', $transaction->date->toDateString())
                : Transaction::whereKey($transaction->id);
            $count = 0;
            foreach ($query->get() as $row) {
                $row->delete();
                $this->releaseStatementLine($row);
                $count++;
            }
            Audit::record('finance.transaction.deleted', subject: $transaction, metadata: ['count' => $count]);

            return $count;
        });
    }

    /** Previsto que aconteceu (paguei / recebi). Sem data, fica a data prevista. */
    public function confirm(Transaction $transaction, ?Carbon $date = null): void
    {
        if (! $transaction->isPlanned()) {
            throw ValidationException::withMessages(['status' => 'Este lançamento já está feito.']);
        }
        $transaction->status = Transaction::DONE;
        if ($date) {
            $transaction->date = $date->toDateString();
        }
        $transaction->save();
        Audit::record('finance.transaction.confirmed', subject: $transaction);
    }

    /**
     * Saldo de hoje de cada conta: saldo inicial + o que já foi feito até hoje (previstos e datas futuras não contam).
     *
     * @return array<string, int> id da conta => centavos
     */
    public function balances(?Collection $accounts = null): array
    {
        $accounts ??= Account::active()->get();
        $today = today()->toDateString();
        $done = fn () => Transaction::query()->done()->where('date', '<=', $today);

        $out = $done()->whereIn('account_id', $accounts->pluck('id'))
            ->selectRaw("account_id, sum(case when kind = 'recebimento' then amount_cents else -amount_cents end) as total")
            ->groupBy('account_id')->pluck('total', 'account_id');
        $in = $done()->where('kind', Transaction::TRANSFER)->whereIn('to_account_id', $accounts->pluck('id'))
            ->selectRaw('to_account_id, sum(amount_cents) as total')->groupBy('to_account_id')->pluck('total', 'to_account_id');

        return $accounts->mapWithKeys(fn (Account $a) => [
            $a->id => $a->opening_balance_cents + (int) ($out[$a->id] ?? 0) + (int) ($in[$a->id] ?? 0),
        ])->all();
    }

    /** @return array{0: Account, 1: ?Account, 2: ?Category} */
    private function check(array $data, bool $requireActive): array
    {
        if ((int) ($data['amount_cents'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        if (! isset(Transaction::KINDS[$data['kind'] ?? ''])) {
            throw ValidationException::withMessages(['kind' => 'Escolha gasto, recebimento ou transferência.']);
        }

        $account = Account::query()->when($requireActive, fn ($q) => $q->active())->find($data['account_id'] ?? null);
        if (! $account) {
            throw ValidationException::withMessages(['account_id' => 'Escolha a conta ou o cartão.']);
        }

        $toAccount = null;
        $category = null;
        if ($data['kind'] === Transaction::TRANSFER) {
            $toAccount = Account::query()->when($requireActive, fn ($q) => $q->active())->find($data['to_account_id'] ?? null);
            if (! $toAccount || $toAccount->id === $account->id) {
                throw ValidationException::withMessages(['to_account_id' => 'Escolha para qual conta foi o dinheiro (diferente da de saída).']);
            }
        } elseif (! empty($data['category_id'])) {
            $category = Category::find($data['category_id']);
            if (! $category || $category->kind !== $data['kind']) {
                throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de '.Str::lower(Transaction::KINDS[$data['kind']]).'.']);
            }
        }

        return [$account, $toAccount, $category];
    }

    /**
     * Apagou um lançamento que veio do extrato (ou que estava conciliado com ele): a linha volta para revisar,
     * como no Studio. Linha dividida perde só aquela parte; sem nenhuma parte, volta para revisar.
     */
    private function releaseStatementLine(Transaction $transaction): void
    {
        if (! $transaction->statement_line_id) {
            return;
        }
        $line = StatementLine::find($transaction->statement_line_id);
        if (! $line) {
            return;
        }

        if ($line->status === StatementLine::SPLIT) {
            $left = array_values(array_filter($line->split_details ?? [], fn ($id) => $id !== $transaction->id));
            $line->update($left === [] ? ['status' => StatementLine::PENDING, 'split_details' => null, 'resolved_at' => null] : ['split_details' => $left]);

            return;
        }
        if (in_array($line->status, [StatementLine::CREATED, StatementLine::MATCHED], true) && $line->transaction_id === $transaction->id) {
            $line->update(['status' => StatementLine::PENDING, 'transaction_id' => null, 'resolved_at' => null]);
        }
    }

    private function statusFor(Account $account, Carbon $date): string
    {
        return $account->isCard() || $date->lte(today()) ? Transaction::DONE : Transaction::PLANNED;
    }

    /** A parcela mantém a fatura dela; muda só se a conta mudou ou se não é parcela (aí segue a data). */
    private function invoiceFor(Transaction $transaction, Account $account, Carbon $date, bool $accountChanged): ?string
    {
        if (! $account->isCard()) {
            return null;
        }
        if ($transaction->series_kind === 'parcelas' && $transaction->invoice_month && ! $accountChanged) {
            return $transaction->invoice_month;
        }

        return $account->invoiceMonthFor($date);
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
