<?php

namespace App\Finance\Http;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Categorizer;
use App\Finance\Statements\StatementLine;
use App\Finance\Statements\StatementResolver;
use App\Finance\Transactions\Transaction;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * O que fazer com cada linha do extrato: lançar (com categoria, ou como transferência entre contas suas),
 * conciliar com o que já estava lançado, dividir em partes, ignorar, desfazer e lançar várias de uma vez.
 */
class StatementLineController
{
    public function __construct(private StatementResolver $resolver) {}

    public function create(Request $request, StatementLine $line): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'string', 'max:30'],
            'to_account_id' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:160'],
            'remember' => ['nullable', 'boolean'],
            'pattern' => ['nullable', 'string', 'max:80'],
        ]);

        if (! empty($data['to_account_id'])) {
            $other = Account::active()->findOrFail($data['to_account_id']);
            $this->resolver->transfer($line, $other->id, $data['description'] ?? null);

            return back()->with('status', 'Lançado como transferência '.($line->isIncome() ? 'vinda de ' : 'para ').$other->name.'.');
        }

        $transaction = $this->resolver->create($line, $data['category_id'] ?? null, $data['description'] ?? null);
        $message = 'Lançado: '.$transaction->description.' · '.Money::format($transaction->amount_cents).($transaction->category_id ? '' : ' (sem categoria — dá para escolher depois em Lançamentos)').'.';

        // "Sempre que aparecer": vira regra e já arruma as outras linhas parecidas que estão para revisar.
        if ($request->boolean('remember') && $transaction->category) {
            $pattern = trim((string) ($data['pattern'] ?? '')) ?: Categorizer::defaultPattern($line->description);
            $result = (new Categorizer)->remember($pattern, $transaction->category);
            $others = max(0, $result['lines']);
            $message .= " Daqui para frente, \"{$result['rule']->pattern}\" vira {$transaction->category->name}".($others ? " (e {$others} linha(s) parecida(s) já receberam a sugestão)" : '').'.';
        }

        return back()->with('status', $message);
    }

    public function match(Request $request, StatementLine $line): RedirectResponse
    {
        $data = $request->validate(['transaction_id' => ['required', 'string', 'max:30']]);
        $transaction = Transaction::findOrFail($data['transaction_id']);
        $this->resolver->match($line, $transaction);

        return back()->with('status', "Conciliado com \"{$transaction->description}\", que já estava lançado. Nada foi lançado de novo.");
    }

    public function split(Request $request, StatementLine $line): RedirectResponse
    {
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:2', 'max:10'],
            'parts.*.amount' => ['nullable', 'string', 'max:20'],
            'parts.*.category_id' => ['nullable', 'string', 'max:30'],
            'parts.*.description' => ['nullable', 'string', 'max:160'],
        ]);
        $parts = array_map(fn ($p) => [
            'amount_cents' => Money::toCents($p['amount'] ?? null) ?? 0,
            'category_id' => $p['category_id'] ?? null,
            'description' => $p['description'] ?? null,
        ], $data['parts']);

        $count = $this->resolver->split($line, $parts);

        return back()->with('status', "Linha dividida em {$count} lançamentos.");
    }

    public function ignore(StatementLine $line): RedirectResponse
    {
        $this->resolver->ignore($line);

        return back()->with('status', 'Linha ignorada (não entra nos lançamentos). Dá para desfazer em "Já resolvidas".');
    }

    public function undo(StatementLine $line): RedirectResponse
    {
        $this->resolver->undo($line);

        return back()->with('status', 'A linha voltou para "Para revisar".');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:selected,with_category'],
            'line_ids' => ['array', 'required_if:scope,selected'],
            'line_ids.*' => ['string', 'max:30'],
            'category_id' => ['nullable', 'string', 'max:30'],
        ], ['line_ids.required_if' => 'Selecione as linhas que quer lançar.']);

        $lines = StatementLine::query()->where('status', StatementLine::PENDING)->whereNull('hidden_at')
            ->when($data['scope'] === 'selected', fn ($q) => $q->whereIn('id', $data['line_ids']))
            ->orderBy('date')->get();
        $result = $this->resolver->bulk($lines, $data['category_id'] ?? null, onlyWithCategory: $data['scope'] === 'with_category');

        $message = "{$result['created']} linha(s) lançada(s).";
        if ($result['skipped_match']) {
            $message .= " {$result['skipped_match']} parecem já estar lançadas e ficaram para você conferir (\"Já está lançado?\").";
        }
        if ($result['skipped_category']) {
            $message .= " {$result['skipped_category']} ficaram para revisar (sem categoria ou de outro tipo).";
        }

        return back()->with('status', $message);
    }
}
