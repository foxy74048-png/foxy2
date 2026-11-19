<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Insights\Export;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionService;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cartões e faturas (fatia 5): a fatura aberta, as que fecharam e falta pagar, as futuras já comprometidas
 * com parcelas, o limite disponível e "Pagar fatura" (transferência da conta para o cartão, ligada à fatura).
 */
class CardController
{
    public function __construct(private CardInvoices $invoices) {}

    public function index(Request $request, TransactionService $service): View
    {
        $service->confirmDueCardCharges();
        $cards = Account::query()->where('kind', Account::CARD)->orderByRaw('archived_at is not null')->orderBy('name')->get();

        return view('finance.cards.index', [
            'cards' => $cards->map(function (Account $card) {
                $outstanding = $this->invoices->outstanding($card);

                return [
                    'card' => $card,
                    'invoices' => $this->invoices->overview($card),
                    'outstanding' => $outstanding,
                    'available' => $card->limit_cents !== null ? $card->limit_cents - $outstanding : null,
                    'committed' => $this->invoices->committed($card),
                ];
            }),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
        ]);
    }

    public function show(Request $request, Account $account, TransactionService $service): View|Response
    {
        abort_unless($account->isCard(), 404);
        $service->confirmDueCardCharges();
        $month = $this->month($request, $account);
        $invoice = $this->invoices->invoice($account, $month, withItems: true);

        if ($format = Export::requested($request)) {
            return (new Export('Fatura '.$account->name.' '.Carbon::createFromFormat('Y-m-d', $month.'-01')->format('m/Y')))
                ->filters(['Fecha em' => $invoice['closing']->format('d/m/Y'), 'Vence em' => $invoice['due']->format('d/m/Y'), 'Situação' => CardInvoices::STATUSES[$invoice['status']]])
                ->section('Compras e estornos', ['Data' => 'date', 'Descrição' => 'text', 'Categoria' => 'text', 'Parcela' => 'text', 'Valor' => 'money'],
                    $invoice['items']->filter(fn (Transaction $t) => $t->account_id === $account->id)->map(fn (Transaction $t) => [
                        $t->date, $t->description, $t->category?->fullName(), $t->seriesLabel(), $t->kind === Transaction::INCOME ? -$t->amount_cents : $t->amount_cents,
                    ]))
                ->section('Pagamentos', ['Data' => 'date', 'De' => 'text', 'Valor' => 'money'],
                    $invoice['items']->filter(fn (Transaction $t) => $t->to_account_id === $account->id)->map(fn (Transaction $t) => [$t->date, $t->account?->name, $t->amount_cents]))
                ->section('Total', ['Gastos' => 'money', 'Pago' => 'money', 'Falta pagar' => 'money'], [[$invoice['charges'], $invoice['payments'], $invoice['remaining']]])
                ->download($format);
        }

        return view('finance.cards.show', [
            'card' => $account,
            'invoice' => $invoice,
            'months' => [Account::addMonths($month, -1), Account::addMonths($month, 1)],
            'openMonth' => $account->invoiceMonthFor(today()),
            'payFrom' => Account::active()->where('kind', '!=', Account::CARD)->orderBy('name')->get(),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
        ]);
    }

    /** Pagar a fatura: transferência da conta escolhida para o cartão, ligada a esta fatura. Não mexe em dinheiro de verdade. */
    public function pay(Request $request, Account $account, TransactionService $service): RedirectResponse
    {
        abort_unless($account->isCard(), 404);
        $data = $request->validate([
            'fatura' => ['required', 'date_format:Y-m'],
            'from_account_id' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
        ], [], ['from_account_id' => 'conta', 'amount' => 'valor', 'date' => 'data']);
        $from = Account::active()->where('kind', '!=', Account::CARD)->find($data['from_account_id']);
        if (! $from) {
            throw ValidationException::withMessages(['from_account_id' => 'Escolha de qual conta saiu o pagamento.']);
        }

        $service->create([
            'kind' => Transaction::TRANSFER, 'account_id' => $from->id, 'to_account_id' => $account->id,
            'amount_cents' => Money::toCents($data['amount']) ?? 0, 'date' => $data['date'],
            'description' => 'Pagamento da fatura '.$account->name.' '.Carbon::createFromFormat('Y-m-d', $data['fatura'].'-01')->format('m/Y'),
            'invoice_month' => $data['fatura'],
        ]);
        $invoice = $this->invoices->invoice($account, $data['fatura']);

        return redirect()->route('finance.cards.show', [$account, 'fatura' => $data['fatura']])
            ->with('status', 'Pagamento registrado. '.($invoice['remaining'] > 0 ? 'Ainda falta '.Money::format($invoice['remaining']).' nesta fatura.' : 'Fatura paga.'));
    }

    /** Fatura pedida na URL; sem pedir, a que está para pagar (fechada e não paga) ou a aberta. */
    private function month(Request $request, Account $card): string
    {
        $wanted = $request->query('fatura');
        if (is_string($wanted) && preg_match('/^\d{4}-\d{2}$/', $wanted)) {
            return $wanted;
        }
        $toPay = $this->invoices->overview($card)->first(fn ($i) => in_array($i['status'], ['fechada', 'parcial', 'atrasada'], true));

        return $toPay['month'] ?? $card->invoiceMonthFor(today());
    }
}
