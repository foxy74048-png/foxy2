<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Insights\Export;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Audit\Audit;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contas e cartões da carteira, com o saldo de hoje. Cartão: limite, fechamento, vencimento e quanto já foi
 * gasto na fatura atual (a fatura completa chega na fatia 5). Arquivar esconde dos formulários sem apagar nada.
 */
class AccountController
{
    public function index(Request $request, TransactionService $service): View|Response
    {
        $accounts = Account::query()->where('kind', '!=', Account::PEOPLE)->orderByRaw('archived_at is not null')->orderBy('kind')->orderBy('name')->get();
        $balances = $service->balances($accounts);
        $invoiceSpent = $this->currentInvoiceSpent($accounts);

        if ($format = Export::requested($request)) {
            return (new Export('Contas e cartões'))
                ->section(null, ['Conta' => 'text', 'Tipo' => 'text', 'Banco' => 'text', 'Saldo hoje' => 'money', 'Limite' => 'money', 'Situação' => 'text'],
                    $accounts->map(fn (Account $a) => [
                        $a->name, $a->kindLabel(), $a->institution, $a->isCard() ? null : $balances[$a->id],
                        $a->limit_cents, $a->archived_at ? 'Arquivada' : 'Em uso',
                    ]))
                ->download($format);
        }

        $active = $accounts->whereNull('archived_at');

        return view('finance.accounts', [
            'accounts' => $accounts,
            'balances' => $balances,
            'invoiceSpent' => $invoiceSpent,
            'total' => $active->reject(fn ($a) => $a->isCard())->sum(fn ($a) => $balances[$a->id]),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.accounts.manage'),
            'kinds' => Account::KINDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = Account::create($this->validated($request));
        Audit::record('finance.account.created', subject: $account);

        return redirect()->to($request->input('back') === 'lancamentos' ? route('finance.transactions') : route('finance.accounts'))
            ->with('status', "\"{$account->name}\" cadastrada.".($request->input('back') === 'lancamentos' ? ' Agora é só lançar.' : ''));
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        $data = $this->validated($request, $account);
        $account->update($data);
        Audit::record('finance.account.updated', subject: $account);

        return back()->with('status', "\"{$account->name}\" salva.");
    }

    public function archive(Request $request, Account $account): RedirectResponse
    {
        $archive = $request->boolean('archive');
        $account->update(['archived_at' => $archive ? now() : null]);
        Audit::record($archive ? 'finance.account.archived' : 'finance.account.restored', subject: $account);

        return back()->with('status', $archive ? "\"{$account->name}\" arquivada: some dos formulários, os lançamentos continuam." : "\"{$account->name}\" voltou a ser usada.");
    }

    public function destroy(Account $account): RedirectResponse
    {
        $used = Transaction::withTrashed()->where(fn ($q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id))->exists();
        if ($used) {
            return back()->withErrors(['account' => "\"{$account->name}\" tem lançamentos. Arquive em vez de excluir: nada se perde."]);
        }
        $account->delete();
        Audit::record('finance.account.deleted', subject: $account);

        return back()->with('status', 'Conta excluída.');
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'kind' => [$account ? 'nullable' : 'required', Rule::in(array_keys(Account::KINDS))],
            'name' => ['required', 'string', 'max:80'],
            'institution' => ['nullable', 'string', 'max:80'],
            'opening_balance' => ['nullable', 'string', 'max:20'],
            'closing_day' => ['nullable', 'integer', 'between:1,31'],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'limit' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => 'nome', 'closing_day' => 'dia do fechamento', 'due_day' => 'dia do vencimento']);

        $kind = $data['kind'] ?? $account?->kind;
        $card = $kind === Account::CARD;
        if ($card && (empty($data['closing_day']) || empty($data['due_day']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['closing_day' => 'No cartão, informe o dia em que a fatura fecha e o dia em que vence (estão na fatura ou no app do banco).']);
        }

        return [
            'kind' => $kind,
            'name' => trim($data['name']),
            'institution' => $data['institution'] ?? null,
            'opening_balance_cents' => $card ? 0 : (Money::toCents($data['opening_balance'] ?? null) ?? 0),
            'closing_day' => $card ? (int) $data['closing_day'] : null,
            'due_day' => $card ? (int) $data['due_day'] : null,
            'limit_cents' => $card ? Money::toCents($data['limit'] ?? null) : null,
        ];
    }

    /** @return array<string, int> gastos menos estornos/pagamentos na fatura deste mês, por cartão */
    private function currentInvoiceSpent($accounts): array
    {
        $invoices = app(\App\Finance\Accounts\CardInvoices::class);

        return $accounts->filter->isCard()->mapWithKeys(fn (Account $card) => [$card->id => $invoices->invoice($card, $card->invoiceMonthFor(today()))['charges']])->all();
    }
}
