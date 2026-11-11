<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Variant;
use App\Commerce\Consignment\Consignment;
use App\Commerce\Consignment\ConsignmentItem;
use App\Commerce\Consignment\ConsignmentService;
use App\Commerce\Finance\FinanceService;
use App\Commerce\Insights\Export;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Supply\SupplyBatch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consignação: "Enviadas" (maletas/sacolinhas com clientes e revendedoras) e "Recebidas" (o que as
 * fornecedoras deixaram, com o vendido até hoje e quanto já se deve).
 */
class ConsignmentController
{
    public function __construct(private ConsignmentService $consignments, private StockService $stock) {}

    public function index(Request $request): View|Response
    {
        $filters = $request->validate(['situacao' => ['nullable', Rule::in(['abertas', 'acertadas', 'todas'])], 'q' => ['nullable', 'string', 'max:80']]);
        $status = $filters['situacao'] ?? 'abertas';
        $query = Consignment::with('customer', 'items')
            ->when($status !== 'todas', fn ($q) => $q->where('status', $status === 'abertas' ? 'aberta' : 'acertada'))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->whereHas('customer', fn ($c) => $c->whereLike('name', '%'.str_replace(['%', '_'], ['\%', '\_'], $t).'%')))
            ->orderByRaw("case when status = 'aberta' then 0 else 1 end")->orderBy('due_on')->latest('sent_on');

        if ($format = Export::requested($request)) {
            return (new Export('Maletas (consignação enviada)'))
                ->filters(['Situação' => ['abertas' => 'Com a pessoa', 'acertadas' => 'Acertadas', 'todas' => 'Todas'][$status]])
                ->section(null, ['Nº' => 'int', 'Pessoa' => 'text', 'Tipo' => 'text', 'Saiu em' => 'date', 'Volta até' => 'date', 'Peças' => 'int', 'Valor' => 'money', 'Situação' => 'text'],
                    $query->get()->map(fn (Consignment $c) => [$c->number, $c->customer?->name, $c->kind === 'revendedora' ? 'Revendedora' : 'Cliente', $c->sent_on, $c->due_on, $c->pieces(), $c->valueCents(), Consignment::STATUSES[$c->status]]))
                ->download($format);
        }

        $open = Consignment::with('items')->where('status', 'aberta')->get();

        return view('commerce.consignments.index', [
            'consignments' => $query->paginate(30)->withQueryString(),
            'filters' => $filters + ['situacao' => $status],
            'summary' => [
                'open' => $open->count(),
                'pieces' => $open->sum(fn ($c) => $c->pieces()),
                'value' => $open->sum(fn ($c) => $c->valueCents()),
                'late' => $open->filter->isLate()->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $customer = $request->query('cliente') ? Customer::find($request->query('cliente')) : null;
        $last = $customer ? Consignment::where('customer_id', $customer->id)->latest('created_at')->first() : null;

        return view('commerce.consignments.create', [
            'customers' => Customer::orderBy('name')->limit(2000)->get(['id', 'name', 'phone']),
            'customer' => $customer,
            'last' => $last,
            'pickable' => $this->pickable(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'string', 'max:30'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'kind' => ['required', Rule::in(array_keys(Consignment::KINDS))],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sent_on' => ['nullable', 'date'],
            'due_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'max:300'],
            'items.*' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ], ['items.required' => 'Escolha pelo menos uma peça.'], ['customer_name' => 'nome', 'due_on' => 'data de volta', 'commission_percent' => 'comissão']);

        $customer = filled($data['customer_id'] ?? null) ? Customer::findOrFail($data['customer_id']) : null;
        if (! $customer) {
            if (blank($data['customer_name'] ?? null)) {
                throw ValidationException::withMessages(['customer_name' => 'Informe com quem a maleta vai ficar.']);
            }
            $customer = Customer::create(array_filter(['name' => trim($data['customer_name']), 'phone' => $data['customer_phone'] ?? null]));
        }

        $consignment = $this->consignments->open($customer, $data, $this->items($data['items']));

        return redirect()->route('commerce.consignments.show', $consignment)->with('status', $consignment->label().' registrada: as peças estão reservadas (não aparecem para venda até o acerto).');
    }

    public function show(Request $request, Consignment $consignment): View|Response
    {
        $consignment->load('items.variant.product', 'customer', 'order');
        if ($format = Export::requested($request)) {
            // Romaneio: a lista que vai junto com a maleta (e que a pessoa confere).
            return (new Export($consignment->label()))
                ->filters(['Saiu em' => $consignment->sent_on->format('d/m/Y'), 'Volta até' => $consignment->due_on?->format('d/m/Y'), 'Telefone' => $consignment->customer?->phone,
                    'Comissão' => $consignment->kind === 'revendedora' ? rtrim(rtrim(number_format($consignment->commission_percent, 2, ',', ''), '0'), ',').'%' : null])
                ->section(null, ['Peça' => 'text', 'Código' => 'text', 'Qtd.' => 'int', 'Preço' => 'money', 'Total' => 'money', 'Voltou' => 'int', 'Ficou' => 'int'],
                    $consignment->items->map(fn (ConsignmentItem $i) => [$i->name, $i->variant?->gtin ?: $i->variant?->sku, $i->quantity, $i->price_cents, $i->price_cents * $i->quantity, $i->returned, $i->sold()]))
                ->download($format);
        }

        return view('commerce.consignments.show', [
            'consignment' => $consignment,
            'pickable' => $consignment->status === 'aberta' ? $this->pickable() : collect(),
            'whatsapp' => $this->whatsappList($consignment),
        ]);
    }

    public function add(Request $request, Consignment $consignment): RedirectResponse
    {
        $data = $request->validate(['items' => ['required', 'array', 'max:300'], 'items.*' => ['nullable', 'integer', 'min:0', 'max:1000']], ['items.required' => 'Escolha pelo menos uma peça.']);
        $this->consignments->add($consignment, $this->items($data['items']));

        return back()->with('status', 'Peças acrescentadas à maleta e reservadas.');
    }

    public function settle(Request $request, Consignment $consignment): RedirectResponse
    {
        $data = $request->validate([
            'returns' => ['nullable', 'array'],
            'returns.*' => ['nullable', 'integer', 'min:0'],
            'payment' => ['required', Rule::in(['pago', 'receber'])],
            'method' => ['nullable', Rule::in(array_diff(array_keys(Order::PAYMENT_METHODS), ['crediario', 'marketplace']))],
            'installments' => ['nullable', 'integer', 'min:1', 'max:'.FinanceService::MAX_INSTALLMENTS],
            'first_due' => ['nullable', 'date'],
        ], [], ['payment' => 'pagamento', 'installments' => 'parcelas', 'first_due' => 'primeiro vencimento']);

        $order = $this->consignments->settle($consignment, array_map('intval', $data['returns'] ?? []), [
            'mode' => $data['payment'], 'method' => $data['method'] ?? 'pix', 'installments' => $data['installments'] ?? 1, 'first_due' => $data['first_due'] ?? null,
        ]);

        return redirect()->route('commerce.consignments.show', $consignment)->with('status', $order
            ? "Acerto feito: o que voltou está disponível de novo e o que ficou virou o pedido #{$order->number} (".Money::format($order->total_cents).').'.($data['payment'] === 'receber' ? ' As parcelas estão em Financeiro › A receber.' : '')
            : 'Acerto feito: tudo voltou e está disponível de novo.');
    }

    /**
     * Consignação recebida (fornecedoras): por remessa em aberto, o vendido até hoje (o que veio menos o
     * que ainda tem), quanto já se deve, quanto entrou de venda, o lucro e o que está parado.
     */
    public function received(Request $request): View
    {
        abort_unless(Access::allows($request->attributes->get('commerce.membership'), 'commerce.catalog.manage'), 403);
        $batches = SupplyBatch::with('items.variant.product')->where('status', 'aberta')->orderBy('closing_on')->get();
        $available = $this->stock->availability($batches->flatMap->items->where('mode', 'consignado')->pluck('variant')->filter()->unique('id'));

        $rows = $batches->map(function (SupplyBatch $batch) use ($available) {
            $items = $batch->items->where('mode', 'consignado')->map(function ($item) use ($available) {
                $has = max(0, min($item->quantity, $available[$item->variant_id] ?? 0));
                $sold = $item->quantity - $has;

                return ['item' => $item, 'has' => $has, 'sold' => $sold, 'owed' => $sold * $item->cost_cents,
                    'revenue' => $sold * (int) ($item->variant?->price_cents ?? 0)];
            });
            $pieces = $items->sum(fn ($i) => $i['item']->quantity);
            $sold = $items->sum('sold');

            return [
                'batch' => $batch, 'items' => $items, 'pieces' => $pieces, 'sold' => $sold,
                'owed' => $items->sum('owed'), 'revenue' => $items->sum('revenue'), 'profit' => $items->sum('revenue') - $items->sum('owed'),
                'rate' => $pieces > 0 ? (int) round($sold * 100 / $pieces) : 0,
                'stuck' => $items->filter(fn ($i) => $i['sold'] === 0)->values(),
                'days' => $batch->closing_on ? (int) today()->diffInDays($batch->closing_on, false) : null,
            ];
        })->filter(fn ($r) => $r['pieces'] > 0)->values();

        return view('commerce.consignments.received', [
            'rows' => $rows,
            'suppliers' => $rows->groupBy(fn ($r) => $r['batch']->supplier)->map(fn (Collection $g) => [
                'pieces' => $g->sum('pieces'), 'sold' => $g->sum('sold'), 'owed' => $g->sum('owed'), 'revenue' => $g->sum('revenue'), 'profit' => $g->sum('profit'),
            ]),
        ]);
    }

    /** Peças que podem ir na maleta (com disponível), para o campo de busca/bipe. */
    private function pickable(): Collection
    {
        $variants = Variant::with('product')->where('status', 'active')->whereHas('product', fn ($q) => $q->where('status', 'active'))->orderBy('sku')->limit(3000)->get();
        $available = $this->stock->availability($variants);

        return $variants->filter(fn (Variant $v) => ($available[$v->id] ?? 0) > 0)->map(fn (Variant $v) => [
            'id' => $v->id, 'name' => $v->fullName(), 'sku' => (string) $v->sku, 'gtin' => (string) $v->gtin,
            'price' => Money::format($v->price_cents), 'cents' => (int) $v->price_cents, 'left' => (int) $available[$v->id],
        ])->values();
    }

    /** @return array<int, array{variant: Variant, quantity: int}> */
    private function items(array $input): array
    {
        $input = array_filter(array_map('intval', $input), fn ($q) => $q > 0);
        $variants = Variant::whereIn('id', array_keys($input))->get()->keyBy('id');
        if ($variants->count() !== count($input)) {
            throw ValidationException::withMessages(['items' => 'Alguma peça não foi encontrada nesta loja. Recarregue a página.']);
        }

        return collect($input)->map(fn ($q, $id) => ['variant' => $variants[$id], 'quantity' => $q])->values()->all();
    }

    /** Link para mandar a lista da maleta no WhatsApp da pessoa. */
    private function whatsappList(Consignment $consignment): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $consignment->customer?->phone);
        if ($digits === '' || $consignment->status !== 'aberta') {
            return null;
        }
        $text = 'Oi, '.strtok((string) $consignment->customer->name, ' ').'! Segue a lista da sua maleta #'.$consignment->number.":\n"
            .$consignment->items->map(fn ($i) => "• {$i->quantity}× {$i->name} — ".Money::format($i->price_cents))->implode("\n")
            ."\nTotal: ".Money::format($consignment->valueCents())
            .($consignment->due_on ? "\nAcerto até ".$consignment->due_on->format('d/m/Y').'.' : '');

        return 'https://wa.me/'.(strlen($digits) <= 11 ? '55'.$digits : $digits).'?text='.rawurlencode($text);
    }
}
