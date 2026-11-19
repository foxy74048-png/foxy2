<?php

namespace App\Finance\Debts;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Quem me deve / a quem eu devo" (fatia 6c). Única porta para os movimentos com pessoas. Quando o dinheiro sai
 * ou entra numa conta, vira transferência com a conta interna "Amigos e família" — emprestar não é gasto e
 * receber de volta não é renda. Nada é cobrado de ninguém: a Foxy só anota e lembra.
 */
class DebtLedger
{
    public function __construct(private TransactionService $transactions) {}

    /** A pessoa pelo id, ou criada pelo nome (sem repetir o mesmo nome). */
    public function contact(?string $id, ?string $name): Contact
    {
        if ($id) {
            return Contact::query()->findOrFail($id);
        }
        $name = trim((string) $name);
        if ($name === '') {
            throw ValidationException::withMessages(['contact' => 'Escolha a pessoa ou escreva o nome dela.']);
        }
        $wanted = Str::lower(Str::ascii($name));

        return Contact::query()->get()->first(fn (Contact $c) => Str::lower(Str::ascii($c->name)) === $wanted)
            ?? Contact::create(['name' => Str::limit($name, 80, '')]);
    }

    /**
     * Emprestei, peguei emprestado, me devolveu, eu paguei de volta. Com conta, registra também a transferência
     * (sai da conta: para "Amigos e família"; entra: de "Amigos e família").
     */
    public function record(Contact $contact, string $kind, int $cents, string $date, ?string $description = null, ?string $accountId = null, ?string $dueDate = null): PersonEntry
    {
        if (! in_array($kind, ['emprestei', 'peguei', 'devolveu', 'paguei'], true)) {
            throw ValidationException::withMessages(['kind' => 'Escolha o que aconteceu.']);
        }
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        $balance = $contact->balanceCents();
        if ($kind === 'devolveu' && $cents > max(0, $balance)) {
            throw ValidationException::withMessages(['amount' => $balance > 0 ? "{$contact->name} te deve ".Money::format($balance).'. Devolveu mais? Registre a diferença como "Peguei emprestado".' : "{$contact->name} não te deve nada agora."]);
        }
        if ($kind === 'paguei' && $cents > max(0, -$balance)) {
            throw ValidationException::withMessages(['amount' => $balance < 0 ? 'Você deve '.Money::format(-$balance)." a {$contact->name}." : "Você não deve nada a {$contact->name} agora."]);
        }
        [$label, $sign, $flow] = PersonEntry::KINDS[$kind];

        return DB::transaction(function () use ($contact, $kind, $cents, $date, $description, $accountId, $dueDate, $sign, $flow, $label) {
            $transactionId = null;
            if ($accountId) {
                $account = Account::active()->findOrFail($accountId);
                $people = Account::people();
                [$from, $to] = $flow === 'sai' ? [$account->id, $people->id] : [$people->id, $account->id];
                $transactionId = $this->transactions->create([
                    'kind' => Transaction::TRANSFER, 'account_id' => $from, 'to_account_id' => $to, 'amount_cents' => $cents, 'date' => $date,
                    'description' => $label.' — '.$contact->name.($description ? ': '.$description : ''),
                ])->first()->id;
            }
            $entry = PersonEntry::create([
                'contact_id' => $contact->id, 'kind' => $kind, 'amount_cents' => $sign * $cents, 'description' => $description,
                'date' => $date, 'due_date' => in_array($kind, ['emprestei', 'peguei'], true) ? $dueDate : null,
                'transaction_id' => $transactionId, 'created_by' => $this->userId(),
            ]);
            Audit::record('finance.debt.recorded', subject: $entry, metadata: ['kind' => $kind]);

            return $entry;
        });
    }

    /**
     * Dividir uma conta: você pagou o total numa conta (ou no cartão); o seu gasto é só a sua parte e o resto fica
     * a receber de cada pessoa. A soma (sua parte + as dos outros) tem que fechar com o total.
     *
     * @param  array<string, int>  $shares  id da pessoa => centavos
     */
    public function split(string $accountId, int $total, int $myShare, ?string $categoryId, string $description, string $date, array $shares): int
    {
        $shares = array_filter($shares, fn ($c) => $c > 0);
        if ($shares === []) {
            throw ValidationException::withMessages(['shares' => 'Informe quanto cada pessoa deve.']);
        }
        $others = array_sum($shares);
        if ($total <= 0 || $myShare < 0 || $myShare + $others !== $total) {
            throw ValidationException::withMessages(['shares' => 'A sua parte ('.Money::format($myShare).') mais as dos outros ('.Money::format($others).') tem que dar o total ('.Money::format($total).').']);
        }
        $account = Account::active()->findOrFail($accountId);
        if ($categoryId && Category::find($categoryId)?->kind !== Transaction::EXPENSE) {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de gasto.']);
        }

        return DB::transaction(function () use ($account, $myShare, $others, $categoryId, $description, $date, $shares) {
            $group = strtolower((string) Str::ulid());
            $expense = $myShare > 0 ? $this->transactions->create([
                'kind' => Transaction::EXPENSE, 'account_id' => $account->id, 'amount_cents' => $myShare, 'date' => $date,
                'description' => $description.' (minha parte)', 'category_id' => $categoryId,
            ])->first() : null;
            $transfer = $this->transactions->create([
                'kind' => Transaction::TRANSFER, 'account_id' => $account->id, 'to_account_id' => Account::people()->id, 'amount_cents' => $others,
                'date' => $date, 'description' => $description.' (parte dos outros)',
            ])->first();

            foreach ($shares as $contactId => $cents) {
                $contact = Contact::query()->findOrFail($contactId);
                PersonEntry::create([
                    'contact_id' => $contact->id, 'kind' => 'dividi', 'amount_cents' => $cents, 'description' => $description,
                    'date' => $date, 'transaction_id' => $transfer->id, 'expense_transaction_id' => $expense?->id, 'group_id' => $group, 'created_by' => $this->userId(),
                ]);
            }
            Audit::record('finance.debt.split', metadata: ['people' => count($shares), 'group' => $group]);

            return count($shares);
        });
    }

    public function lendObject(Contact $contact, string $what, string $date): PersonEntry
    {
        return PersonEntry::create(['contact_id' => $contact->id, 'kind' => 'objeto', 'amount_cents' => 0, 'description' => Str::limit(trim($what), 160, ''), 'date' => $date, 'created_by' => $this->userId()]);
    }

    public function returnObject(PersonEntry $entry, bool $returned = true): void
    {
        if ($entry->kind !== 'objeto') {
            throw ValidationException::withMessages(['entry' => 'Só objeto emprestado é devolvido assim.']);
        }
        $entry->update(['returned_at' => $returned ? now() : null]);
    }

    /** Apaga o movimento e a transferência dele. Conta dividida: apaga a divisão inteira (todas as pessoas e os lançamentos). */
    public function delete(PersonEntry $entry): int
    {
        return DB::transaction(function () use ($entry) {
            $entries = $entry->group_id ? PersonEntry::where('group_id', $entry->group_id)->get() : collect([$entry]);
            $ids = $entries->flatMap(fn ($e) => [$e->transaction_id, $e->expense_transaction_id])->filter()->unique();
            foreach (Transaction::whereIn('id', $ids)->get() as $t) {
                $this->transactions->delete($t);
            }
            $entries->each->delete();

            return $entries->count();
        });
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
