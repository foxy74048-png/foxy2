<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Goals\Goal;
use App\Finance\Goals\GoalEntry;
use App\Finance\Insights\Export;
use App\Finance\Insights\Indicators;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Metas de guardar dinheiro (fatia 6b): quanto falta, quanto guardar por mês para chegar no prazo e se está no
 * ritmo. "Guardei" e "Tirei" entram na meta; com conta e origem, viram transferência de verdade entre contas.
 */
class GoalController
{
    public function index(Request $request): View|Response
    {
        $goals = Goal::query()->withSum('entries', 'amount_cents')->with('account')
            ->orderByRaw('archived_at is not null')->orderByRaw('achieved_at is not null')->orderBy('deadline')->orderBy('created_at')->get();

        if ($format = Export::requested($request)) {
            return (new Export('Metas'))
                ->section(null, ['Meta' => 'text', 'Quanto quer juntar' => 'money', 'Juntado' => 'money', 'Falta' => 'money', 'Prazo' => 'date', 'Por mês até o prazo' => 'money', 'Situação' => 'text'],
                    $goals->map(function (Goal $g) {
                        $p = $g->progress();

                        return [$g->name, $g->target_cents, $p['saved'], $p['missing'], $g->deadline, $p['per_month'], $this->statusLabel($g, $p)];
                    }))
                ->download($format);
        }

        $avgExpense = Indicators::for(today())->summary()['avg_expense'];

        return view('finance.goals', [
            'goals' => $goals->map(fn (Goal $g) => ['goal' => $g, 'progress' => $g->progress(), 'status' => $this->statusLabel($g, $g->progress()),
                'entries' => $g->entries()->with('transaction.account', 'transaction.toAccount')->latest('date')->latest()->limit(5)->get()]),
            'accounts' => Account::active()->orderBy('name')->get(),
            'savingsAccounts' => Account::active()->where('kind', 'poupanca')->get(),
            'hasReserve' => $goals->contains(fn (Goal $g) => str_contains(mb_strtolower($g->name), 'reserva')),
            'reserveTarget' => $avgExpense > 0 ? (int) (ceil($avgExpense * 6 / 10000) * 10000) : null,
            'avgExpense' => $avgExpense,
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $goal = Goal::create($this->validated($request));
        Audit::record('finance.goal.created', subject: $goal);

        return back()->with('status', "Meta \"{$goal->name}\" criada.".($goal->progress()['per_month'] ? ' Para chegar no prazo: '.Money::format($goal->progress()['per_month']).' por mês.' : ''));
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        $goal->update($this->validated($request, $goal));
        $this->checkAchieved($goal);

        return back()->with('status', 'Meta salva.');
    }

    public function archive(Request $request, Goal $goal): RedirectResponse
    {
        $archive = $request->boolean('archive');
        $goal->update(['archived_at' => $archive ? now() : null]);

        return back()->with('status', $archive ? "\"{$goal->name}\" guardada no histórico." : "\"{$goal->name}\" voltou para as metas.");
    }

    public function destroy(Goal $goal): RedirectResponse
    {
        if ($goal->entries()->whereNotNull('transaction_id')->exists()) {
            return back()->withErrors(['goal' => 'Esta meta tem transferências de verdade ligadas a ela. Arquive em vez de apagar (as transferências continuam nos lançamentos).']);
        }
        $goal->delete();
        Audit::record('finance.goal.deleted', subject: $goal);

        return back()->with('status', 'Meta apagada.');
    }

    /** "Guardei" (positivo) ou "Tirei" (negativo). Com conta na meta e conta de origem/destino, vira transferência. */
    public function entry(Request $request, Goal $goal, TransactionService $service): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:guardei,tirei'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
            'other_account_id' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:160'],
        ], [], ['amount' => 'valor', 'date' => 'data']);
        $cents = Money::toCents($data['amount']) ?? 0;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        $saving = $data['direction'] === 'guardei';
        if (! $saving && $cents > $goal->savedCents()) {
            throw ValidationException::withMessages(['amount' => 'A meta só tem '.Money::format($goal->savedCents()).' juntado.']);
        }

        $other = ! empty($data['other_account_id']) ? Account::active()->find($data['other_account_id']) : null;
        if (! empty($data['other_account_id']) && (! $other || $other->id === $goal->account_id)) {
            throw ValidationException::withMessages(['other_account_id' => 'Escolha outra conta sua (diferente da conta da meta).']);
        }

        DB::transaction(function () use ($goal, $service, $data, $cents, $saving, $other) {
            $transactionId = null;
            if ($goal->account_id && $other) {
                [$from, $to] = $saving ? [$other->id, $goal->account_id] : [$goal->account_id, $other->id];
                $transactionId = $service->create([
                    'kind' => Transaction::TRANSFER, 'account_id' => $from, 'to_account_id' => $to, 'amount_cents' => $cents,
                    'date' => $data['date'], 'description' => ($saving ? 'Guardar: ' : 'Tirar de: ').$goal->name, 'notes' => $data['note'] ?? null,
                ])->first()->id;
            }
            GoalEntry::create([
                'goal_id' => $goal->id, 'amount_cents' => $saving ? $cents : -$cents, 'date' => $data['date'],
                'note' => $data['note'] ?? null, 'transaction_id' => $transactionId, 'created_by' => $this->userId(),
            ]);
        });
        $justAchieved = $this->checkAchieved($goal->fresh());
        $p = $goal->fresh()->progress();

        return back()->with('status', ($justAchieved ? "Meta \"{$goal->name}\" alcançada! Parabéns." : ($saving ? 'Guardado: ' : 'Retirado: ').Money::format($cents).'. Falta '.Money::format($p['missing']).'.')
            .($p['per_month'] && ! $p['done'] ? ' Para chegar no prazo: '.Money::format($p['per_month']).' por mês.' : ''));
    }

    /** Apaga uma entrada; a transferência ligada a ela também sai dos lançamentos. */
    public function destroyEntry(GoalEntry $entry, TransactionService $service): RedirectResponse
    {
        DB::transaction(function () use ($entry, $service) {
            if ($entry->transaction_id && ($t = Transaction::find($entry->transaction_id))) {
                $service->delete($t);
            }
            $entry->delete();
        });
        $goal = $entry->goal()->first();
        if ($goal && $goal->achieved_at && ! $goal->progress()['done']) {
            $goal->update(['achieved_at' => null]);
        }

        return back()->with('status', 'Entrada apagada.');
    }

    private function validated(Request $request, ?Goal $goal = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'target' => ['required', 'string', 'max:20'],
            'deadline' => $goal ? ['nullable', 'date'] : ['nullable', 'date', 'after:today'],
            'account_id' => ['nullable', 'string', 'max:30'],
            'start' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => 'nome', 'target' => 'quanto quer juntar', 'deadline' => 'prazo', 'start' => 'quanto já tem']);
        $target = Money::toCents($data['target']) ?? 0;
        if ($target <= 0) {
            throw ValidationException::withMessages(['target' => 'Informe quanto quer juntar.']);
        }
        $account = ! empty($data['account_id']) ? Account::active()->where('kind', '!=', Account::CARD)->find($data['account_id']) : null;
        if (! empty($data['account_id']) && ! $account) {
            throw ValidationException::withMessages(['account_id' => 'Escolha uma conta (não cartão).']);
        }

        return ['name' => trim($data['name']), 'target_cents' => $target, 'deadline' => $data['deadline'] ?? null,
            'account_id' => $account?->id, 'start_cents' => max(0, Money::toCents($data['start'] ?? null) ?? 0)];
    }

    private function checkAchieved(Goal $goal): bool
    {
        $done = $goal->progress()['done'];
        if ($done && ! $goal->achieved_at) {
            $goal->update(['achieved_at' => now()]);
            Audit::record('finance.goal.achieved', subject: $goal);

            return true;
        }
        if (! $done && $goal->achieved_at) {
            $goal->update(['achieved_at' => null]);
        }

        return false;
    }

    private function statusLabel(Goal $goal, array $p): string
    {
        return match (true) {
            $goal->archived_at !== null => 'No histórico',
            $p['done'] => 'Alcançada',
            $p['late'] => 'Prazo passou',
            $p['on_track'] === true => 'No ritmo',
            $p['on_track'] === false => 'Atrasada',
            default => 'Sem prazo',
        };
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
