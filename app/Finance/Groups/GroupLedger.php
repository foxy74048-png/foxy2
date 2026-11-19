<?php

namespace App\Finance\Groups;

use App\Finance\Debts\DebtLedger;
use App\Finance\Debts\PersonEntry;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Despesas em grupo (fatia 6d). Cada despesa tem quem pagou e a parte de cada pessoa; pagamentos entre elas
 * acertam as contas. Saldo de cada pessoa = o que pagou − a parte dela + o que já passou aos outros − o que
 * recebeu: positivo, tem a receber; negativo, deve.
 */
class GroupLedger
{
    public function __construct(private DebtLedger $debts) {}

    /** @param  array<int, string>  $names  os outros (você entra sozinho) */
    public function create(string $name, array $names = [], array $contactIds = []): Group
    {
        return DB::transaction(function () use ($name, $names, $contactIds) {
            $group = Group::create(['name' => Str::limit(trim($name), 80, ''), 'token' => Str::random(40)]);
            GroupMember::create(['group_id' => $group->id, 'name' => 'Você', 'is_me' => true]);
            foreach ($contactIds as $id) {
                $this->addMember($group, $id, null);
            }
            foreach ($names as $n) {
                if (trim((string) $n) !== '') {
                    $this->addMember($group, null, $n);
                }
            }
            Audit::record('finance.group.created', subject: $group);

            return $group;
        });
    }

    /** Pessoa nova no grupo: de "Quem me deve" (contato) ou só o nome (vira contato na hora de levar o saldo). */
    public function addMember(Group $group, ?string $contactId, ?string $name): GroupMember
    {
        $contact = $contactId ? \App\Finance\Debts\Contact::query()->findOrFail($contactId) : null;
        $name = trim((string) ($contact?->name ?? $name));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Escreva o nome da pessoa.']);
        }
        $wanted = Str::lower(Str::ascii($name));
        $existing = $group->members()->get()->first(fn (GroupMember $m) => ! $m->is_me && Str::lower(Str::ascii($m->name)) === $wanted);

        return $existing ?? GroupMember::create(['group_id' => $group->id, 'contact_id' => $contact?->id, 'name' => Str::limit($name, 80, '')]);
    }

    public function removeMember(GroupMember $member): void
    {
        if ($member->is_me) {
            throw ValidationException::withMessages(['member' => 'Você não sai do seu próprio grupo.']);
        }
        $used = GroupExpense::where('paid_by', $member->id)->exists() || GroupShare::where('member_id', $member->id)->exists()
            || GroupPayment::where(fn ($q) => $q->where('from_member_id', $member->id)->orWhere('to_member_id', $member->id))->exists();
        if ($used) {
            throw ValidationException::withMessages(['member' => "{$member->name} já tem despesas ou pagamentos no grupo. Apague-os antes."]);
        }
        $member->delete();
    }

    /**
     * Despesa. Igual: divide entre as pessoas marcadas (os centavos que sobram vão para as primeiras). Valores:
     * cada um com o seu, e a soma tem que fechar.
     *
     * @param  array<int, string>  $participants  ids (modo igual)
     * @param  array<string, int>  $amounts  id => centavos (modo valores)
     */
    public function addExpense(Group $group, string $description, int $cents, string $date, string $payerId, string $mode, array $participants = [], array $amounts = []): GroupExpense
    {
        $this->open($group);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        $members = $group->members()->get()->keyBy('id');
        if (! $members->has($payerId)) {
            throw ValidationException::withMessages(['paid_by' => 'Escolha quem pagou.']);
        }

        if ($mode === 'valores') {
            $shares = array_filter($amounts, fn ($v, $id) => $v > 0 && $members->has($id), ARRAY_FILTER_USE_BOTH);
            if ($shares === [] || array_sum($shares) !== $cents) {
                throw ValidationException::withMessages(['shares' => 'As partes somam '.Money::format(array_sum($shares)).' e a despesa é de '.Money::format($cents).'. A soma tem que fechar.']);
            }
        } else {
            $ids = array_values(array_filter($participants, fn ($id) => $members->has($id)));
            if ($ids === []) {
                throw ValidationException::withMessages(['shares' => 'Marque entre quem a despesa é dividida.']);
            }
            $each = intdiv($cents, count($ids));
            $rest = $cents - $each * count($ids);
            $shares = [];
            foreach ($ids as $i => $id) {
                $shares[$id] = $each + ($i < $rest ? 1 : 0);
            }
            $mode = 'igual';
        }

        return DB::transaction(function () use ($group, $description, $cents, $date, $payerId, $mode, $shares) {
            $expense = GroupExpense::create(['group_id' => $group->id, 'description' => Str::limit(trim($description), 160, ''), 'amount_cents' => $cents, 'date' => $date, 'paid_by' => $payerId, 'split_mode' => $mode, 'created_by' => $this->userId()]);
            foreach ($shares as $memberId => $share) {
                GroupShare::create(['expense_id' => $expense->id, 'member_id' => $memberId, 'amount_cents' => $share]);
            }

            return $expense;
        });
    }

    public function addPayment(Group $group, string $fromId, string $toId, int $cents, string $date): GroupPayment
    {
        $this->open($group);
        $members = $group->members()->get()->keyBy('id');
        if (! $members->has($fromId) || ! $members->has($toId) || $fromId === $toId || $cents <= 0) {
            throw ValidationException::withMessages(['payment' => 'Escolha quem pagou, para quem (outra pessoa) e o valor.']);
        }

        return GroupPayment::create(['group_id' => $group->id, 'from_member_id' => $fromId, 'to_member_id' => $toId, 'amount_cents' => $cents, 'date' => $date]);
    }

    /**
     * O saldo de cada pessoa: gastou (a parte dela), pagou (as despesas que ela pagou), passou e recebeu nos
     * pagamentos, e o resultado.
     *
     * @return Collection<int, array{member: GroupMember, share: int, paid: int, sent: int, received: int, net: int}>
     */
    public function balances(Group $group): Collection
    {
        $members = $group->members()->get();
        $expenses = $group->expenses()->with('shares')->get();
        $payments = $group->payments()->get();
        $shares = $expenses->flatMap->shares;

        return $members->map(function (GroupMember $m) use ($expenses, $payments, $shares) {
            $share = (int) $shares->where('member_id', $m->id)->sum('amount_cents');
            $paid = (int) $expenses->where('paid_by', $m->id)->sum('amount_cents');
            $sent = (int) $payments->where('from_member_id', $m->id)->sum('amount_cents');
            $received = (int) $payments->where('to_member_id', $m->id)->sum('amount_cents');

            return ['member' => $m, 'share' => $share, 'paid' => $paid, 'sent' => $sent, 'received' => $received, 'net' => $paid - $share + $sent - $received];
        })->values();
    }

    /**
     * Quem paga quem, com o menor número de pagamentos: quem deve mais paga primeiro a quem tem mais a receber.
     *
     * @return array<int, array{from: GroupMember, to: GroupMember, amount: int}>
     */
    public function settlements(Collection $balances): array
    {
        $debtors = $balances->filter(fn ($b) => $b['net'] < 0)->sortBy('net')->map(fn ($b) => ['m' => $b['member'], 'v' => -$b['net']])->values()->all();
        $creditors = $balances->filter(fn ($b) => $b['net'] > 0)->sortByDesc('net')->map(fn ($b) => ['m' => $b['member'], 'v' => $b['net']])->values()->all();
        $out = [];
        $i = $j = 0;
        while ($i < count($debtors) && $j < count($creditors)) {
            $amount = min($debtors[$i]['v'], $creditors[$j]['v']);
            if ($amount > 0) {
                $out[] = ['from' => $debtors[$i]['m'], 'to' => $creditors[$j]['m'], 'amount' => $amount];
            }
            $debtors[$i]['v'] -= $amount;
            $creditors[$j]['v'] -= $amount;
            if ($debtors[$i]['v'] <= 0) {
                $i++;
            }
            if ($creditors[$j]['v'] <= 0) {
                $j++;
            }
        }

        return $out;
    }

    /**
     * Leva para "Quem me deve" o que falta acertar entre você e cada pessoa (pela lista de quem paga quem).
     * Acertos entre os outros não são seus e ficam só no grupo. Encerra o grupo.
     */
    public function sendToDebts(Group $group): int
    {
        if ($group->sent_to_debts_at) {
            throw ValidationException::withMessages(['group' => 'Este grupo já foi levado para "Quem me deve".']);
        }

        return DB::transaction(function () use ($group) {
            $count = 0;
            foreach ($this->settlements($this->balances($group)) as $s) {
                if (! $s['from']->is_me && ! $s['to']->is_me) {
                    continue;
                }
                $other = $s['from']->is_me ? $s['to'] : $s['from'];
                $contact = $this->debts->contact($other->contact_id, $other->name);
                $other->update(['contact_id' => $contact->id]);
                PersonEntry::create([
                    'contact_id' => $contact->id, 'kind' => 'grupo', 'description' => $group->name, 'date' => today()->toDateString(),
                    // Ela paga você: ela te deve (+). Você paga ela: você deve (−).
                    'amount_cents' => $s['to']->is_me ? $s['amount'] : -$s['amount'], 'created_by' => $this->userId(),
                ]);
                $count++;
            }
            $group->update(['sent_to_debts_at' => now(), 'closed_at' => $group->closed_at ?? now()]);
            Audit::record('finance.group.sent_to_debts', subject: $group, metadata: ['count' => $count]);

            return $count;
        });
    }

    /** Texto do resumo para mandar no WhatsApp do grupo. */
    public function summaryText(Group $group, ?string $link = null, ?string $myName = null): string
    {
        $balances = $this->balances($group);
        $name = fn (GroupMember $m) => $m->is_me ? ($myName ?: 'Quem criou o grupo') : $m->name;
        $lines = ["*{$group->name}*", 'Total: '.Money::format($balances->sum('share')), ''];
        foreach ($balances as $b) {
            $lines[] = $name($b['member']).': gastou '.Money::format($b['share']).', pagou '.Money::format($b['paid']);
        }
        $settlements = $this->settlements($balances);
        $lines[] = '';
        $lines[] = $settlements ? 'Para acertar:' : 'Tudo acertado!';
        foreach ($settlements as $s) {
            $lines[] = '• '.$name($s['from']).' → '.$name($s['to']).': '.Money::format($s['amount']);
        }
        if ($link) {
            $lines[] = '';
            $lines[] = 'Detalhes: '.$link;
        }

        return implode("\n", $lines);
    }

    private function open(Group $group): void
    {
        if ($group->isClosed()) {
            throw ValidationException::withMessages(['group' => 'O grupo está encerrado. Reabra para mudar.']);
        }
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
