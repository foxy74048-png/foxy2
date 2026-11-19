<?php

namespace App\Finance\Statements;

use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Audit\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O que fazer com cada linha do extrato. Todo lançamento sai pelo TransactionService (fonte "extrato", ligado à
 * linha). Cada ação confere, dentro da transação do banco, se a linha ainda está para revisar (clique duplo,
 * "Voltar" do navegador): se não estiver, avisa em vez de lançar duas vezes.
 */
class StatementResolver
{
    public function __construct(private TransactionService $transactions, private StatementImporter $importer) {}

    /** Lança a linha como gasto ou recebimento, com a categoria (opcional) e a descrição (opcional, senão a do extrato). */
    public function create(StatementLine $line, ?string $categoryId, ?string $description = null): Transaction
    {
        return $this->locked($line, function (StatementLine $line) use ($categoryId, $description) {
            $transaction = $this->transactions->create($this->base($line, $description) + [
                'kind' => $line->kind(), 'category_id' => $categoryId,
            ])->first();
            $line->update(['status' => StatementLine::CREATED, 'transaction_id' => $transaction->id, 'resolved_at' => now()]);

            return $transaction;
        });
    }

    /** A linha é dinheiro passando entre duas contas suas (ex.: pagar a fatura, guardar na poupança). */
    public function transfer(StatementLine $line, string $otherAccountId, ?string $description = null): Transaction
    {
        return $this->locked($line, function (StatementLine $line) use ($otherAccountId, $description) {
            [$from, $to] = $line->isIncome() ? [$otherAccountId, $line->account_id] : [$line->account_id, $otherAccountId];
            $transaction = $this->transactions->create(['account_id' => $from, 'to_account_id' => $to, 'kind' => Transaction::TRANSFER] + $this->base($line, $description))->first();
            $line->update(['status' => StatementLine::CREATED, 'transaction_id' => $transaction->id, 'resolved_at' => now()]);

            return $transaction;
        });
    }

    /**
     * Já estava lançado (à mão, pelo WhatsApp, ou previsto): liga os dois, nada é criado. O previsto vira feito
     * com a data do extrato.
     */
    public function match(StatementLine $line, Transaction $transaction): void
    {
        $this->locked($line, function (StatementLine $line) use ($transaction) {
            $transaction = Transaction::query()->lockForUpdate()->findOrFail($transaction->id);
            if (! $this->importer->compatible($transaction, $line)) {
                throw ValidationException::withMessages(['line' => 'Esse lançamento não confere com a linha (valor, entrada/saída ou já ligado a outro extrato).']);
            }
            if ($transaction->isPlanned()) {
                $this->transactions->confirm($transaction, Carbon::parse($line->date));
            }
            $transaction->forceFill(['statement_line_id' => $line->id])->save();
            $line->update(['status' => StatementLine::MATCHED, 'transaction_id' => $transaction->id, 'resolved_at' => now()]);
            Audit::record('finance.statement.matched', subject: $line, metadata: ['transaction' => $transaction->id]);
        });
    }

    /**
     * Divide a linha em partes (ex.: mercado + farmácia na mesma compra). A soma tem que fechar com a linha.
     *
     * @param  array<int, array{amount_cents: int, category_id: ?string, description: ?string}>  $parts
     */
    public function split(StatementLine $line, array $parts): int
    {
        $parts = array_values(array_filter($parts, fn ($p) => (int) ($p['amount_cents'] ?? 0) > 0));
        if (count($parts) < 2) {
            throw ValidationException::withMessages(['parts' => 'Para dividir, informe pelo menos duas partes com valor.']);
        }
        $sum = array_sum(array_map(fn ($p) => (int) $p['amount_cents'], $parts));
        if ($sum !== abs($line->amount_cents)) {
            throw ValidationException::withMessages(['parts' => 'As partes somam '.number_format($sum / 100, 2, ',', '.').' e a linha é de '.number_format(abs($line->amount_cents) / 100, 2, ',', '.').'. A soma tem que fechar.']);
        }

        return $this->locked($line, function (StatementLine $line) use ($parts) {
            $ids = [];
            foreach ($parts as $part) {
                $ids[] = $this->transactions->create([
                    'kind' => $line->kind(), 'category_id' => $part['category_id'] ?? null, 'amount_cents' => (int) $part['amount_cents'],
                ] + $this->base($line, $part['description'] ?? null))->first()->id;
            }
            $line->update(['status' => StatementLine::SPLIT, 'split_details' => $ids, 'resolved_at' => now()]);

            return count($ids);
        });
    }

    public function ignore(StatementLine $line): void
    {
        $this->locked($line, fn (StatementLine $line) => $line->update(['status' => StatementLine::IGNORED, 'resolved_at' => now()]));
    }

    /**
     * Volta a linha para revisar: o que foi lançado por ela é apagado; a conciliação só solta a ligação (o
     * lançamento que já existia continua, feito).
     */
    public function undo(StatementLine $line): void
    {
        DB::transaction(function () use ($line) {
            $line = StatementLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($line->isPending()) {
                throw ValidationException::withMessages(['line' => 'Essa linha já está para revisar.']);
            }

            $created = match ($line->status) {
                StatementLine::CREATED => array_filter([$line->transaction_id]),
                StatementLine::SPLIT => $line->split_details ?? [],
                default => [],
            };
            if ($line->status === StatementLine::MATCHED && $line->transaction_id) {
                Transaction::whereKey($line->transaction_id)->update(['statement_line_id' => null]);
            }
            $line->update(['status' => StatementLine::PENDING, 'transaction_id' => null, 'split_details' => null, 'resolved_at' => null]);
            // Com a linha já para revisar, apagar não mexe nela de novo.
            Transaction::whereIn('id', $created)->get()->each(fn (Transaction $t) => $t->delete());
            Audit::record('finance.statement.undone', subject: $line);
        });
    }

    /**
     * Lança várias de uma vez com a categoria sugerida (ou a escolhida para todas). Pula a linha que parece já
     * estar lançada (essa pede conferência) e a que não tem categoria quando $onlyWithCategory.
     *
     * @return array{created: int, skipped_match: int, skipped_category: int}
     */
    public function bulk(Collection $lines, ?string $categoryId = null, bool $onlyWithCategory = false): array
    {
        $pending = $lines->where('status', StatementLine::PENDING);
        $matches = $this->importer->matches($pending);
        $out = ['created' => 0, 'skipped_match' => 0, 'skipped_category' => 0];

        foreach ($pending as $line) {
            if (isset($matches[$line->id])) {
                $out['skipped_match']++;

                continue;
            }
            $category = $categoryId ?? $line->suggested_category_id;
            if ($onlyWithCategory && ! $category) {
                $out['skipped_category']++;

                continue;
            }
            try {
                $this->create($line, $category);
                $out['created']++;
            } catch (ValidationException) {
                // Linha resolvida por outra pessoa no meio do caminho, ou categoria do tipo errado escolhida para todas.
                $out['skipped_category']++;
            }
        }

        return $out;
    }

    private function base(StatementLine $line, ?string $description): array
    {
        return [
            'account_id' => $line->account_id,
            'amount_cents' => abs($line->amount_cents),
            'date' => $line->date->toDateString(),
            'description' => trim((string) $description) !== '' ? trim($description) : $line->description,
            'source' => 'extrato',
            'statement_line_id' => $line->id,
        ];
    }

    /** Roda a ação com a linha travada e conferida (ainda para revisar). */
    private function locked(StatementLine $line, callable $action): mixed
    {
        return DB::transaction(function () use ($line, $action) {
            $fresh = StatementLine::query()->lockForUpdate()->findOrFail($line->id);
            if (! $fresh->isPending()) {
                throw ValidationException::withMessages(['line' => 'Essa linha já foi resolvida (talvez por outro clique ou outra pessoa). A lista foi atualizada.']);
            }

            return $action($fresh);
        });
    }
}
