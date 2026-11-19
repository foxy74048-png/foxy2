<?php

namespace App\Finance\Partners;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Finance;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Finance\Wallets\Wallet;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sócios e MEI (fatia 7). Única porta para os movimentos de sócio: pró-labore, retirada e distribuição saem do
 * caixa; aporte entra. Todos são lançamentos com partner_id, fora do "lucro do mês". Com o sócio tendo aceitado,
 * a saída vira também um recebimento na carteira pessoal dele (par ligado por linked_transaction_id).
 */
class PartnerService
{
    /** Limite de faturamento do MEI por ano (R$ 81.000). */
    public const MEI_LIMIT_CENTS = 8100000;

    public function __construct(private TransactionService $transactions, private ContextStore $contexts) {}

    public function record(Partner $partner, string $kind, int $cents, string $date, string $accountId, ?string $description = null): Transaction
    {
        if (! isset(Partner::KINDS[$kind])) {
            throw ValidationException::withMessages(['kind' => 'Escolha o tipo do movimento.']);
        }
        [$label, $txKind] = Partner::KINDS[$kind];

        return DB::transaction(function () use ($partner, $kind, $cents, $date, $accountId, $description, $label, $txKind) {
            $transaction = $this->transactions->create([
                'kind' => $txKind, 'account_id' => $accountId, 'amount_cents' => $cents, 'date' => $date,
                'description' => $label.' — '.$partner->name.($description ? ': '.$description : ''),
                'source' => 'socio', 'partner_id' => $partner->id, 'partner_kind' => $kind,
            ])->first();
            $this->mirror($transaction, $partner);
            Audit::record('finance.partner.movement', subject: $transaction, metadata: ['kind' => $kind]);

            return $transaction;
        });
    }

    /** "Foi gasto pessoal do sócio": a linha do extrato vira retirada dele (fatia 7, "isso é pessoal"). */
    public function lineAsWithdrawal(StatementLine $line, Partner $partner): Transaction
    {
        return DB::transaction(function () use ($line, $partner) {
            $fresh = StatementLine::query()->lockForUpdate()->findOrFail($line->id);
            if (! $fresh->isPending() || $fresh->isIncome()) {
                throw ValidationException::withMessages(['line' => 'Só uma saída ainda para revisar pode virar retirada de sócio.']);
            }
            $transaction = $this->record($partner, 'retirada', abs($fresh->amount_cents), $fresh->date->toDateString(), $fresh->account_id, $fresh->description);
            $transaction->forceFill(['statement_line_id' => $fresh->id, 'source' => 'extrato'])->save();
            $fresh->update(['status' => StatementLine::CREATED, 'transaction_id' => $transaction->id, 'resolved_at' => now()]);

            return $transaction;
        });
    }

    /** Acerto direto entre sócios, fora do caixa: "o Bruno pagou R$ 400 à Ana". */
    public function settle(Partner $from, Partner $to, int $cents, string $date, ?string $note = null): Settlement
    {
        if ($from->id === $to->id || $cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Escolha dois sócios diferentes e um valor maior que zero.']);
        }

        return Settlement::create(['from_partner_id' => $from->id, 'to_partner_id' => $to->id, 'amount_cents' => $cents, 'date' => $date, 'note' => $note, 'created_by' => $this->userId()]);
    }

    /**
     * O período em números: lucro (entrou − saiu sem os movimentos de sócio), quanto cabe a cada um pela parte,
     * quanto cada um tirou (pró-labore + retirada + distribuição − aportes, ± acertos) e a diferença.
     *
     * @return array{income: int, expense: int, profit: int, partners: Collection, shares_total: int}
     */
    public function summary(Carbon $from, Carbon $to): array
    {
        $range = [$from->toDateString(), $to->toDateString()];
        $business = Transaction::query()->done()->whereNull('partner_id')->whereBetween('date', $range);
        $income = (int) (clone $business)->where('kind', Transaction::INCOME)->sum('amount_cents');
        $expense = (int) (clone $business)->where('kind', Transaction::EXPENSE)->sum('amount_cents');
        $profit = $income - $expense;

        $moves = Transaction::query()->done()->whereNotNull('partner_id')->whereBetween('date', $range)->get();
        $settlements = Settlement::query()->whereBetween('date', $range)->get();
        $partners = Partner::query()->whereNull('archived_at')->orderBy('name')->get();

        $rows = $partners->map(function (Partner $p) use ($moves, $settlements, $profit) {
            $mine = $moves->where('partner_id', $p->id);
            $by = fn (string $kind) => (int) $mine->where('partner_kind', $kind)->sum('amount_cents');
            $out = $by('pro_labore') + $by('retirada') + $by('distribuicao');
            $in = $by('aporte');
            // Quem pagou direto ao outro sócio "devolveu"; quem recebeu, "tirou".
            $settled = (int) $settlements->where('to_partner_id', $p->id)->sum('amount_cents') - (int) $settlements->where('from_partner_id', $p->id)->sum('amount_cents');
            $took = $out - $in + $settled;
            $due = (int) round(max(0, $profit) * $p->share_bp / 10000);

            return ['partner' => $p, 'pro_labore' => $by('pro_labore'), 'retirada' => $by('retirada'), 'distribuicao' => $by('distribuicao'),
                'aporte' => $in, 'settled' => $settled, 'took' => $took, 'due' => $due, 'diff' => $took - $due];
        });

        return ['income' => $income, 'expense' => $expense, 'profit' => $profit, 'partners' => $rows, 'shares_total' => (int) $partners->sum('share_bp')];
    }

    /**
     * Sugestão de acerto: quem tirou a mais paga a quem tirou a menos, até zerar (com 2 sócios, uma frase só).
     *
     * @return array<int, array{from: Partner, to: Partner, amount: int}>
     */
    public function suggestedSettlements(Collection $rows): array
    {
        $over = $rows->filter(fn ($r) => $r['diff'] > 0)->map(fn ($r) => ['p' => $r['partner'], 'v' => $r['diff']])->values()->all();
        $under = $rows->filter(fn ($r) => $r['diff'] < 0)->map(fn ($r) => ['p' => $r['partner'], 'v' => -$r['diff']])->values()->all();
        $out = [];
        $i = $j = 0;
        while ($i < count($over) && $j < count($under)) {
            $amount = min($over[$i]['v'], $under[$j]['v']);
            if ($amount > 0) {
                $out[] = ['from' => $over[$i]['p'], 'to' => $under[$j]['p'], 'amount' => $amount];
            }
            $over[$i]['v'] -= $amount;
            $under[$j]['v'] -= $amount;
            if ($over[$i]['v'] <= 0) {
                $i++;
            }
            if ($under[$j]['v'] <= 0) {
                $j++;
            }
        }

        return $out;
    }

    /** MEI: quanto faturou no ano (recebimentos do negócio, sem aportes) e quanto falta para o limite. */
    public function meiYear(int $year): array
    {
        $total = (int) Transaction::query()->done()->whereNull('partner_id')->where('kind', Transaction::INCOME)
            ->whereBetween('date', ["{$year}-01-01", "{$year}-12-31"])->sum('amount_cents');

        return ['total' => $total, 'limit' => self::MEI_LIMIT_CENTS, 'pct' => $total / self::MEI_LIMIT_CENTS, 'left' => max(0, self::MEI_LIMIT_CENTS - $total)];
    }

    /** Lembrete do DAS: lançamento previsto todo mês, no dia do DAS, por 12 meses (categoria DAS, se existir). */
    public function dasReminder(Wallet $wallet, string $accountId, int $cents): int
    {
        $day = $wallet->das_day ?: 20;
        $first = today()->day($day);
        if ($first->lt(today())) {
            $first->addMonthNoOverflow();
        }

        return $this->transactions->create([
            'kind' => Transaction::EXPENSE, 'account_id' => $accountId, 'amount_cents' => $cents, 'date' => $first->toDateString(),
            'description' => 'DAS do MEI', 'category_id' => Category::where('system_key', 'das')->value('id'), 'mode' => 'mensal', 'count' => 12,
        ])->count();
    }

    /**
     * Carteiras pessoais do sócio (com login) onde a retirada pode entrar: as que ele é dono, com as contas.
     *
     * @return Collection<int, array{organization: Organization, wallet: Wallet, accounts: Collection}>
     */
    public function mirrorOptions(int|string $userId): Collection
    {
        $orgIds = Membership::active()->where('user_id', $userId)->where('role', 'owner')
            ->whereHas('organization', fn ($q) => $q->where('integration_id', Finance::integration()->getKey()))->pluck('organization_id');
        $wallets = Wallet::withoutGlobalScopes()->whereIn('organization_id', $orgIds)->where('kind', Wallet::PERSONAL)->get();

        return $wallets->map(fn (Wallet $w) => [
            'organization' => $w->organization, 'wallet' => $w,
            'accounts' => Account::withoutGlobalScopes()->where('organization_id', $w->organization_id)->whereNull('archived_at')
                ->whereNotIn('kind', [Account::PEOPLE, Account::CARD])->orderBy('name')->get(),
        ])->filter(fn ($o) => $o['accounts']->isNotEmpty())->values();
    }

    /** O sócio (logado) escolhe a conta da carteira pessoal dele onde as retiradas entram (ou desliga). */
    public function setMirror(Partner $partner, int|string $userId, ?string $accountId): void
    {
        if ((string) $partner->user_id !== (string) $userId) {
            throw ValidationException::withMessages(['mirror' => 'Só o próprio sócio escolhe a carteira pessoal dele.']);
        }
        if (! $accountId) {
            $partner->update(['mirror_organization_id' => null, 'mirror_account_id' => null]);

            return;
        }
        $option = $this->mirrorOptions($userId)->first(fn ($o) => $o['accounts']->contains('id', $accountId));
        if (! $option) {
            throw ValidationException::withMessages(['mirror' => 'Escolha uma conta de uma carteira pessoal sua.']);
        }
        $partner->update(['mirror_organization_id' => $option['organization']->getKey(), 'mirror_account_id' => $accountId]);
    }

    /** Lança a saída como recebimento na carteira pessoal do sócio, se ele escolheu onde. */
    private function mirror(Transaction $transaction, Partner $partner): void
    {
        if ($transaction->kind !== Transaction::EXPENSE || ! $partner->mirror_account_id || ! $partner->user_id) {
            return;
        }
        $organization = Organization::find($partner->mirror_organization_id);
        $stillOwner = $organization && Membership::active()->where('organization_id', $organization->getKey())->where('user_id', $partner->user_id)->exists();
        if (! $stillOwner) {
            return;
        }
        $businessName = Wallet::first()?->name ?? 'negócio';

        $mirror = $this->contexts->run(ExecutionContext::forUser($organization, $partner->user_id), fn () => $this->transactions->create([
            'kind' => Transaction::INCOME, 'account_id' => $partner->mirror_account_id, 'amount_cents' => $transaction->amount_cents,
            'date' => $transaction->date->toDateString(), 'description' => Partner::KINDS[$transaction->partner_kind][0].' de '.$businessName,
            'category_id' => Category::where('system_key', 'pro_labore')->value('id'), 'source' => 'socio', 'linked_transaction_id' => $transaction->id,
        ])->first());
        $transaction->forceFill(['linked_transaction_id' => $mirror->id])->save();
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
