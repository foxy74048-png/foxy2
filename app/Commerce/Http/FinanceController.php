<?php

namespace App\Commerce\Http;

use App\Commerce\Catalog\Money;
use App\Commerce\Finance\FinanceService;
use App\Commerce\Finance\Payable;
use App\Commerce\Finance\Receivable;
use App\Commerce\Finance\WhatsAppMessage;
use App\Commerce\Insights\Export;
use App\Commerce\Orders\Customer;
use App\Platform\Audit\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Financeiro da loja: a receber (crediário/fiado e avulsas), a pagar e fluxo de caixa. Baixas manuais;
 * cobranças e lembretes por WhatsApp ficam no WhatsAppController.
 */
class FinanceController
{
    public const TABS = ['receber' => 'A receber', 'pagar' => 'A pagar', 'fluxo' => 'Fluxo de caixa'];

    public const SITUATIONS = ['aberto' => 'Em aberto', 'vencidas' => 'Vencidas', 'recebido' => 'Recebidas/pagas', 'cancelado' => 'Canceladas', 'todas' => 'Todas'];

    public const METHODS = ['pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'cartao_debito' => 'Cartão de débito', 'cartao_credito' => 'Cartão de crédito', 'boleto' => 'Boleto', 'transferencia' => 'Transferência'];

    public function __construct(private FinanceService $finance) {}

    public function index(Request $request): View|Response
    {
        $filters = $request->validate([
            'aba' => ['nullable', Rule::in(array_keys(self::TABS))],
            'situacao' => ['nullable', Rule::in(array_keys(self::SITUATIONS))],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date'],
            'busca' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', Rule::in(array_keys(Payable::CATEGORIES))],
            'semanas' => ['nullable', 'integer', Rule::in([4, 8, 13, 26])],
        ]);
        $tab = $filters['aba'] ?? 'receber';
        $format = Export::requested($request);

        if ($tab === 'fluxo') {
            $flow = $this->flow((int) ($filters['semanas'] ?? 8));
            if ($format) {
                return (new Export('Fluxo de caixa'))->filters(['Semanas' => (string) ($filters['semanas'] ?? 8)])
                    ->section(null, ['Período' => 'text', 'Entradas previstas' => 'money', 'Saídas previstas' => 'money', 'Saldo do período' => 'money', 'Saldo acumulado' => 'money'],
                        collect($flow['rows'])->map(fn ($r) => [$r['label'], $r['in'], $r['out'], $r['in'] - $r['out'], $r['balance']]))
                    ->download($format);
            }

            return view('commerce.finance.index', ['tab' => $tab, 'filters' => $filters, 'flow' => $flow]);
        }

        $query = $tab === 'receber' ? $this->receivables($filters) : $this->payables($filters);
        if ($format) {
            return $this->export($tab, $query->get(), $filters, $format);
        }

        return view('commerce.finance.index', [
            'tab' => $tab,
            'filters' => $filters,
            'rows' => $query->paginate(30)->withQueryString(),
            'summary' => $tab === 'receber' ? $this->summary(Receivable::query(), 'received_at', 'received_cents') : $this->summary(Payable::query(), 'paid_at', 'paid_cents'),
            'customers' => $tab === 'receber' ? Customer::orderBy('name')->limit(3000)->get(['id', 'name', 'phone']) : collect(),
        ]);
    }

    // ---------------------------------------------------------------- A receber

    public function storeReceivable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:'.FinanceService::MAX_INSTALLMENTS],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['description' => 'descrição', 'amount' => 'valor', 'due_date' => 'vencimento', 'installments' => 'parcelas']);

        $customerId = filled($data['customer_id'] ?? null) ? Customer::findOrFail($data['customer_id'])->id
            : (filled($data['customer_name'] ?? null) ? Customer::create(['name' => $data['customer_name'], 'phone' => $data['customer_phone'] ?? null])->id : null);
        $created = $this->finance->installments(['customer_id' => $customerId, 'description' => $data['description'], 'notes' => $data['notes'] ?? null],
            Money::toCents($data['amount']) ?? 0, (int) ($data['installments'] ?? 1), Carbon::parse($data['due_date']));

        return redirect()->route('commerce.finance')->with('status', count($created) > 1 ? count($created).' parcelas lançadas.' : 'Conta a receber lançada.');
    }

    public function showReceivable(Receivable $receivable): View
    {
        return view('commerce.finance.receivable', [
            'r' => $receivable->load('customer', 'order'),
            'messages' => WhatsAppMessage::where('receivable_id', $receivable->id)->latest()->limit(30)->get(),
            'siblings' => $receivable->order_id ? Receivable::where('order_id', $receivable->order_id)->orderBy('due_date')->get() : collect(),
        ]);
    }

    public function updateReceivable(Request $request, Receivable $receivable): RedirectResponse
    {
        if (! $receivable->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Só dá para editar uma conta em aberto (reabra antes, se precisar).']);
        }
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'], 'amount' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['description' => 'descrição', 'amount' => 'valor', 'due_date' => 'vencimento']);
        $cents = Money::toCents($data['amount']) ?? 0;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor maior que zero.']);
        }
        // Mudou o vencimento: os lembretes recomeçam.
        $reminders = Carbon::parse($data['due_date'])->isSameDay($receivable->due_date) ? $receivable->reminders : null;
        $receivable->update(['description' => $data['description'], 'amount_cents' => $cents, 'due_date' => $data['due_date'], 'notes' => $data['notes'] ?? null, 'reminders' => $reminders]);
        Audit::record('commerce.receivable.updated', subject: $receivable);

        return back()->with('status', 'Conta salva.');
    }

    public function receive(Request $request, Receivable $receivable): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'string', 'max:20'], 'method' => ['nullable', Rule::in(array_keys(self::METHODS))], 'date' => ['nullable', 'date'],
        ]);
        $rest = $this->finance->receive($receivable, Money::toCents($data['amount'] ?? null), $data['method'] ?? null, isset($data['date']) ? Carbon::parse($data['date']) : null);

        return back()->with('status', $rest ? 'Recebimento parcial registrado. Ficou em aberto '.Money::format($rest->amount_cents).'.' : 'Recebimento registrado.');
    }

    public function reopenReceivable(Receivable $receivable): RedirectResponse
    {
        $this->finance->reopenReceivable($receivable);

        return back()->with('status', 'Conta reaberta.');
    }

    public function cancelReceivable(Receivable $receivable): RedirectResponse
    {
        $this->finance->cancelReceivable($receivable);

        return back()->with('status', 'Conta cancelada.');
    }

    // ---------------------------------------------------------------- A pagar

    public function storePayable(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'], 'supplier' => ['nullable', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Payable::CATEGORIES))], 'amount' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date'], 'mode' => ['required', Rule::in(['unica', 'parcelas', 'mensal'])],
            'count' => ['nullable', 'integer', 'min:2', 'max:36'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['description' => 'descrição', 'amount' => 'valor', 'due_date' => 'vencimento', 'count' => 'quantidade']);

        $created = $this->finance->payables($data + ['amount_cents' => Money::toCents($data['amount']) ?? 0], $data['mode'], (int) ($data['count'] ?? 2));

        return redirect()->route('commerce.finance', ['aba' => 'pagar'])->with('status', count($created) > 1 ? count($created).' contas lançadas.' : 'Conta a pagar lançada.');
    }

    public function updatePayable(Request $request, Payable $payable): RedirectResponse
    {
        if (! $payable->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Só dá para editar uma conta em aberto (reabra antes, se precisar).']);
        }
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'], 'supplier' => ['nullable', 'string', 'max:120'],
            'category' => ['required', Rule::in(array_keys(Payable::CATEGORIES))], 'amount' => ['required', 'string', 'max:20'],
            'due_date' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['description' => 'descrição', 'amount' => 'valor', 'due_date' => 'vencimento']);
        $payable->update(['amount_cents' => Money::toCents($data['amount']) ?? $payable->amount_cents] + collect($data)->except('amount')->all());
        Audit::record('commerce.payable.updated', subject: $payable);

        return back()->with('status', 'Conta salva.');
    }

    public function pay(Request $request, Payable $payable): RedirectResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'string', 'max:20'], 'method' => ['nullable', Rule::in(array_keys(self::METHODS))], 'date' => ['nullable', 'date']]);
        $this->finance->pay($payable, Money::toCents($data['amount'] ?? null), $data['method'] ?? null, isset($data['date']) ? Carbon::parse($data['date']) : null);

        return back()->with('status', 'Pagamento registrado.');
    }

    public function reopenPayable(Payable $payable): RedirectResponse
    {
        $this->finance->reopenPayable($payable);

        return back()->with('status', 'Conta reaberta.');
    }

    public function cancelPayable(Request $request, Payable $payable): RedirectResponse
    {
        $count = $this->finance->cancelPayable($payable, $request->boolean('future'));

        return back()->with('status', $count > 1 ? "{$count} contas canceladas (esta e as próximas)." : 'Conta cancelada.');
    }

    // ---------------------------------------------------------------- consultas

    private function receivables(array $f)
    {
        return Receivable::with('customer', 'order')
            ->when(true, fn ($q) => $this->situation($q, $f['situacao'] ?? 'aberto', 'recebido'))
            ->when($f['de'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->when($f['busca'] ?? null, function ($q, $term) {
                if (preg_match('/^#?(\d+)$/', trim($term), $m)) {
                    $q->whereHas('order', fn ($o) => $o->where('number', (int) $m[1]));

                    return;
                }
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->whereLike('description', $like)->orWhereHas('customer', fn ($c) => $c->whereLike('name', $like)->orWhereLike('phone', $like)));
            })
            ->orderBy('due_date')->orderBy('id');
    }

    private function payables(array $f)
    {
        return Payable::query()
            ->when(true, fn ($q) => $this->situation($q, $f['situacao'] ?? 'aberto', 'pago'))
            ->when($f['de'] ?? null, fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->when($f['categoria'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['busca'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->whereLike('description', $like)->orWhereLike('supplier', $like));
            })
            ->orderBy('due_date')->orderBy('id');
    }

    private function situation($query, string $situation, string $done): void
    {
        match ($situation) {
            'aberto' => $query->where('status', 'aberto'),
            'vencidas' => $query->where('status', 'aberto')->where('due_date', '<', today()->toDateString()),
            'recebido' => $query->where('status', $done),
            'cancelado' => $query->where('status', 'cancelado'),
            default => null,
        };
    }

    private function summary($base, string $doneAt, string $doneCents): array
    {
        $open = (clone $base)->where('status', 'aberto');

        return [
            'open' => (int) (clone $open)->sum('amount_cents'),
            'open_count' => (clone $open)->count(),
            'overdue' => (int) (clone $open)->where('due_date', '<', today()->toDateString())->sum('amount_cents'),
            'overdue_count' => (clone $open)->where('due_date', '<', today()->toDateString())->count(),
            'week' => (int) (clone $open)->whereBetween('due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])->sum('amount_cents'),
            'month_done' => (int) (clone $base)->whereNotNull($doneAt)->where($doneAt, '>=', now()->startOfMonth())->sum($doneCents),
        ];
    }

    /** Entradas e saídas previstas por semana (o que está vencido entra na primeira linha). */
    private function flow(int $weeks): array
    {
        $start = today()->startOfWeek(Carbon::MONDAY);
        $rows = [[
            'label' => 'Vencidas (em atraso)',
            'in' => (int) Receivable::open()->where('due_date', '<', today()->toDateString())->sum('amount_cents'),
            'out' => (int) Payable::open()->where('due_date', '<', today()->toDateString())->sum('amount_cents'),
        ]];
        for ($i = 0; $i < $weeks; $i++) {
            $from = $i === 0 ? today() : $start->copy()->addWeeks($i);
            $to = $start->copy()->addWeeks($i)->endOfWeek(Carbon::SUNDAY);
            $rows[] = [
                'label' => ($i === 0 ? 'Esta semana' : 'Semana de '.$from->format('d/m')).' (até '.$to->format('d/m').')',
                'in' => (int) Receivable::open()->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->sum('amount_cents'),
                'out' => (int) Payable::open()->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->sum('amount_cents'),
            ];
        }
        $balance = 0;
        foreach ($rows as &$row) {
            $balance += $row['in'] - $row['out'];
            $row['balance'] = $balance;
        }

        return ['rows' => $rows, 'in' => array_sum(array_column($rows, 'in')), 'out' => array_sum(array_column($rows, 'out'))];
    }

    private function export(string $tab, $rows, array $f, string $format): Response
    {
        $filters = ['Situação' => self::SITUATIONS[$f['situacao'] ?? 'aberto'], 'Vencimento de' => $f['de'] ?? null, 'até' => $f['ate'] ?? null, 'Busca' => $f['busca'] ?? null];

        if ($tab === 'receber') {
            return (new Export('Contas a receber'))->filters($filters)
                ->section(null, ['Vencimento' => 'date', 'Cliente' => 'text', 'Telefone' => 'text', 'Descrição' => 'text', 'Parcela' => 'text', 'Valor' => 'money', 'Situação' => 'text', 'Recebido em' => 'date', 'Valor recebido' => 'money', 'Pedido' => 'text'],
                    $rows->map(fn (Receivable $r) => [$r->due_date, $r->customer?->name, $r->customer?->phone, $r->description, $r->installmentLabel(), $r->amount_cents, $r->statusLabel(),
                        $r->received_at, $r->received_cents, $r->order ? '#'.$r->order->number : null]))
                ->download($format);
        }

        return (new Export('Contas a pagar'))->filters($filters + ['Categoria' => isset($f['categoria']) ? Payable::CATEGORIES[$f['categoria']] : null])
            ->section(null, ['Vencimento' => 'date', 'Descrição' => 'text', 'Fornecedor' => 'text', 'Categoria' => 'text', 'Parcela' => 'text', 'Valor' => 'money', 'Situação' => 'text', 'Pago em' => 'date', 'Valor pago' => 'money'],
                $rows->map(fn (Payable $p) => [$p->due_date, $p->description, $p->supplier, Payable::CATEGORIES[$p->category] ?? $p->category, $p->installmentLabel(), $p->amount_cents, $p->statusLabel(), $p->paid_at, $p->paid_cents]))
            ->download($format);
    }
}
