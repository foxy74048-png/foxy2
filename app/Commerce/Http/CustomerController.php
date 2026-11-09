<?php

namespace App\Commerce\Http;

use App\Commerce\Finance\Receivable;
use App\Commerce\Finance\WhatsAppMessage;
use App\Commerce\Insights\Export;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CustomerController
{
    public function index(Request $request): View|Response
    {
        $filters = $request->validate(['busca' => ['nullable', 'string', 'max:100'], 'ordem' => ['nullable', 'in:nome,gasto,recente']]);

        $customers = Customer::query()
            ->withCount(['orders as orders_count' => fn ($q) => $q->whereIn('status', Order::SOLD)])
            ->withSum(['orders as spent_cents' => fn ($q) => $q->whereIn('status', Order::SOLD)], 'total_cents')
            ->withMax('orders as last_order_at', 'placed_at')
            ->when($filters['busca'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
                $q->where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('phone', $like)->orWhereLike('email', $like));
            })
            ->when(($filters['ordem'] ?? 'gasto') === 'gasto', fn ($q) => $q->orderByDesc('spent_cents'))
            ->when(($filters['ordem'] ?? null) === 'recente', fn ($q) => $q->orderByDesc('last_order_at'))
            ->orderBy('name');

        if ($format = Export::requested($request)) {
            return (new Export('Clientes'))
                ->filters(['Busca' => $filters['busca'] ?? null, 'Ordem' => ['nome' => 'Nome', 'gasto' => 'Quem mais comprou', 'recente' => 'Compra mais recente'][$filters['ordem'] ?? 'gasto']])
                ->section(null, ['Cliente' => 'text', 'Telefone' => 'text', 'E-mail' => 'text', 'Cidade' => 'text', 'UF' => 'text', 'Pedidos' => 'int', 'Total comprado' => 'money', 'Última compra' => 'date'],
                    $customers->get()->map(fn (Customer $c) => [$c->name, $c->phone, $c->email, $c->city, $c->state, $c->orders_count, (int) $c->spent_cents, $c->last_order_at ? Carbon::parse($c->last_order_at) : null]))
                ->download($format);
        }
        $customers = $customers->paginate(30)->withQueryString();

        return view('commerce.customers.index', ['customers' => $customers, 'filters' => $filters]);
    }

    public function show(Request $request, Customer $customer): View|Response
    {
        $orders = $customer->orders()->latest('placed_at')->get();
        if ($format = Export::requested($request)) {
            return (new Export('Pedidos de '.$customer->name))
                ->filters(['Telefone' => $customer->phone, 'E-mail' => $customer->email])
                ->section(null, ['Pedido' => 'int', 'Data' => 'datetime', 'Canal' => 'text', 'Situação' => 'text', 'Total' => 'money'],
                    $orders->map(fn (Order $o) => [$o->number, $o->placed_at, $o->channelLabel(), $o->statusLabel(), $o->total_cents]))
                ->download($format);
        }
        $sold = $orders->whereIn('status', Order::SOLD);

        return view('commerce.customers.show', [
            'customer' => $customer,
            'orders' => $orders,
            'receivables' => Receivable::where('customer_id', $customer->id)->whereIn('status', ['aberto', 'recebido'])->orderByRaw("case when status = 'aberto' then 0 else 1 end")->orderBy('due_date')->limit(50)->get(),
            'messages' => WhatsAppMessage::where('customer_id', $customer->id)->latest()->limit(20)->get(),
            'spent' => (int) $sold->sum('total_cents'),
            'ticket' => $sold->count() ? intdiv((int) $sold->sum('total_cents'), $sold->count()) : 0,
        ]);
    }

    /** Cliente novo pela tela (sem precisar de pedido). */
    public function store(Request $request): RedirectResponse
    {
        $customer = Customer::create($this->validated($request));

        return redirect()->route('commerce.customers.show', $customer)->with('status', 'Cliente cadastrada.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($this->validated($request));

        return back()->with('status', 'Cliente salvo.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'nome', 'document' => 'CPF/CNPJ', 'state' => 'UF']);
    }
}
