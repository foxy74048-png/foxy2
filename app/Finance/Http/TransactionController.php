<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Insights\Export;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use App\Finance\Transactions\TransactionService;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lançamentos: lançar em 3 toques (tipo, valor, descrição; o resto já vem preenchido), lista do mês com
 * filtros e totais, editar, apagar e confirmar o previsto. Tudo passa pelo TransactionService.
 */
class TransactionController
{
    public function __construct(private TransactionService $service) {}

    public function index(Request $request): View|Response
    {
        $filters = TransactionFilters::fromRequest($request);
        $accounts = Account::query()->orderByRaw('archived_at is not null')->orderBy('name')->get();
        $categories = Category::with('parent')->get();
        $base = fn () => $filters->apply(Transaction::query());

        if ($format = Export::requested($request)) {
            return $this->export($format, $filters, $base(), $accounts, $categories);
        }

        $totals = $base()->selectRaw("
            sum(case when kind = 'recebimento' and status = 'efetivado' then amount_cents else 0 end) as income,
            sum(case when kind = 'gasto' and status = 'efetivado' then amount_cents else 0 end) as expense,
            sum(case when kind = 'recebimento' and status = 'previsto' then amount_cents else 0 end) as planned_income,
            sum(case when kind = 'gasto' and status = 'previsto' then amount_cents else 0 end) as planned_expense
        ")->first();

        return view('finance.transactions.index', [
            'filters' => $filters,
            'rows' => $base()->with(['account', 'toAccount', 'category.parent'])->orderByDesc('date')->orderByDesc('created_at')->paginate(50)->withQueryString(),
            'totals' => array_map('intval', $totals?->only(['income', 'expense', 'planned_income', 'planned_expense']) ?? []),
            'accounts' => $accounts,
            'activeAccounts' => $accounts->whereNull('archived_at')->values(),
            'categoryOptions' => Category::options(),
            'categoryNames' => $categories->mapWithKeys(fn ($c) => [$c->id => $c->fullName()])->all(),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
            'defaultAccount' => $this->defaultAccount($request, $accounts),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);
        $created = $this->service->create($data);

        $first = $created->first();
        $what = Transaction::KINDS[$first->kind].' de '.Money::format($data['amount_cents']);
        $more = match ($data['mode']) {
            'parcelas' => " em {$created->count()}x",
            'mensal' => ", todo mês por {$created->count()} meses",
            default => '',
        };
        $request->session()->put('finance.last_account', $first->account_id);

        return redirect()->route('finance.transactions', ['mes' => $first->date->format('Y-m')])
            ->with('status', "{$what}{$more} lançado.".($first->isPlanned() ? ' Ficou como previsto: confirme quando acontecer.' : ''));
    }

    public function edit(Transaction $transaction): View
    {
        return view('finance.transactions.edit', [
            'transaction' => $transaction->load('account', 'toAccount', 'category'),
            'accounts' => Account::query()->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', array_filter([$transaction->account_id, $transaction->to_account_id])))->orderBy('name')->get(),
            'categoryOptions' => Category::options($transaction->category_id),
            'seriesCount' => $transaction->series_id ? Transaction::where('series_id', $transaction->series_id)->where('date', '>', $transaction->date->toDateString())->count() : 0,
        ]);
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $this->validated($request, creating: false, kind: $transaction->kind);
        $count = $this->service->update($transaction, $data, $request->boolean('following'));

        return redirect()->route('finance.transactions', ['mes' => $transaction->date->format('Y-m')])
            ->with('status', $count > 1 ? "Salvo neste e em mais ".($count - 1).' lançamento(s) da série.' : 'Lançamento salvo.');
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        $month = $transaction->date->format('Y-m');
        $count = $this->service->delete($transaction, $request->boolean('following'));

        return redirect()->route('finance.transactions', ['mes' => $month])->with('status', $count > 1 ? "{$count} lançamentos apagados." : 'Lançamento apagado.');
    }

    public function confirm(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $this->service->confirm($transaction, isset($data['date']) ? Carbon::parse($data['date']) : null);

        return back()->with('status', $transaction->kind === Transaction::INCOME ? 'Recebimento confirmado.' : 'Pagamento confirmado.');
    }

    private function validated(Request $request, bool $creating, ?string $kind = null): array
    {
        $data = $request->validate([
            'kind' => [$creating ? 'required' : 'nullable', Rule::in(array_keys(Transaction::KINDS))],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'description' => ['required', 'string', 'max:160'],
            'account_id' => ['required', 'string', 'max:30'],
            'to_account_id' => ['nullable', 'string', 'max:30'],
            'category_id' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'mode' => ['nullable', Rule::in(array_keys(TransactionService::MODES))],
            'count' => ['nullable', 'integer', 'min:2', 'max:'.TransactionService::MAX_INSTALLMENTS],
        ], [], ['amount' => 'valor', 'date' => 'data', 'description' => 'descrição', 'account_id' => 'conta', 'count' => 'quantidade']);

        return [
            'kind' => $kind ?? $data['kind'],
            'amount_cents' => Money::toCents($data['amount']) ?? 0,
            'mode' => $data['mode'] ?? 'unica',
        ] + $data;
    }

    /** A última conta usada nesta sessão; senão a primeira conta que não é cartão. */
    private function defaultAccount(Request $request, $accounts): ?string
    {
        $active = $accounts->whereNull('archived_at');
        $last = $request->session()->get('finance.last_account');

        return $active->firstWhere('id', $last)?->id ?? $active->first(fn ($a) => ! $a->isCard())?->id ?? $active->first()?->id;
    }

    private function export(string $format, TransactionFilters $filters, $query, $accounts, $categories): Response
    {
        $accountNames = $accounts->pluck('name', 'id')->all();
        $categoryNames = $categories->mapWithKeys(fn ($c) => [$c->id => $c->fullName()])->all();
        $rows = $query->with(['account', 'toAccount', 'category.parent'])->orderBy('date')->orderBy('created_at')->get();

        return (new Export('Lançamentos'))
            ->filters($filters->labels($accountNames, $categoryNames))
            ->section(null, [
                'Data' => 'date', 'Descrição' => 'text', 'Tipo' => 'text', 'Categoria' => 'text', 'Conta' => 'text',
                'Valor' => 'money', 'Situação' => 'text', 'Parcela' => 'text', 'Fatura' => 'text:planilha', 'Observação' => 'text:planilha',
            ], $rows->map(fn (Transaction $t) => [
                $t->date,
                $t->description,
                Transaction::KINDS[$t->kind],
                $t->isTransfer() ? 'Para '.$t->toAccount?->name : $t->category?->fullName(),
                $t->account?->name,
                $t->kind === Transaction::EXPENSE ? -$t->amount_cents : $t->amount_cents,
                Transaction::STATUSES[$t->status] ?? $t->status,
                $t->seriesLabel(),
                $t->invoice_month,
                $t->notes,
            ]))
            ->download($format);
    }
}
