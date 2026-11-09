<?php

namespace App\Commerce\Http;

use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController
{
    public function index(Request $request): View
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
            ->orderBy('name')
            ->paginate(30)->withQueryString();

        return view('commerce.customers.index', ['customers' => $customers, 'filters' => $filters]);
    }

    public function show(Customer $customer): View
    {
        $orders = $customer->orders()->latest('placed_at')->get();
        $sold = $orders->whereIn('status', Order::SOLD);

        return view('commerce.customers.show', [
            'customer' => $customer,
            'orders' => $orders,
            'spent' => (int) $sold->sum('total_cents'),
            'ticket' => $sold->count() ? intdiv((int) $sold->sum('total_cents'), $sold->count()) : 0,
        ]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'document' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['name' => 'nome', 'document' => 'CPF/CNPJ', 'state' => 'UF']));

        return back()->with('status', 'Cliente salvo.');
    }
}
