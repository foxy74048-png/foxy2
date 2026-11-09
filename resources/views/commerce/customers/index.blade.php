<x-core::layout title="Clientes" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')<a class="button" href="#nova-cliente" onclick="document.getElementById('nova-cliente').open = true">Nova cliente</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    <details class="card" id="nova-cliente" style="margin-bottom:16px;" @if ($errors->any()) open @endif>
        <summary style="cursor:pointer;font-weight:600;">Cadastrar cliente</summary>
        <form method="POST" action="{{ route('commerce.customers.store') }}" class="form-grid" style="margin-top:12px;align-items:end;">
            @csrf
            <div><label for="n-name">Nome</label><input id="n-name" name="name" value="{{ old('name') }}" required maxlength="255"></div>
            <div><label for="n-phone">WhatsApp</label><input id="n-phone" name="phone" value="{{ old('phone') }}" maxlength="30" placeholder="11 99999-0000"></div>
            <div><label for="n-email">E-mail</label><input id="n-email" name="email" type="email" value="{{ old('email') }}" maxlength="255"></div>
            <div><label for="n-city">Cidade</label><input id="n-city" name="city" value="{{ old('city') }}" maxlength="80"></div>
            <div><button type="submit">Cadastrar</button></div>
        </form>
    </details>

    <form class="card form-grid" method="GET" action="{{ route('commerce.customers') }}" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div><label for="busca">Nome, telefone ou e-mail</label><input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}"></div>
        <div>
            <label for="ordem">Ordenar por</label>
            <select id="ordem" name="ordem">
                <option value="gasto" @selected(($filters['ordem'] ?? 'gasto') === 'gasto')>Quem mais comprou</option>
                <option value="recente" @selected(($filters['ordem'] ?? '') === 'recente')>Compra mais recente</option>
                <option value="nome" @selected(($filters['ordem'] ?? '') === 'nome')>Nome</option>
            </select>
        </div>
        <div><button type="submit">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($customers->isEmpty())
            <div class="empty">Nenhum cliente ainda. Eles entram sozinhos pelos pedidos.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Cliente</th><th>Contato</th><th>Cidade</th><th style="text-align:right;">Pedidos</th><th style="text-align:right;">Total comprado</th><th>Última compra</th><th></th></tr></thead>
                <tbody>
                    @foreach ($customers as $c)
                        <tr>
                            <td><a href="{{ route('commerce.customers.show', $c) }}"><strong>{{ $c->name }}</strong></a></td>
                            <td>{{ $c->phone ?? $c->email ?? '—' }}</td>
                            <td>{{ $c->city ? $c->city.($c->state ? '/'.$c->state : '') : '—' }}</td>
                            <td style="text-align:right;">{{ $c->orders_count }}</td>
                            <td style="text-align:right;">{{ $money((int) $c->spent_cents) }}</td>
                            <td>{{ $c->last_order_at ? \Illuminate\Support\Carbon::parse($c->last_order_at)->format('d/m/Y') : '—' }}</td>
                            <td style="text-align:right;"><a class="button secondary" href="{{ route('commerce.customers.show', $c) }}">Editar</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $customers->links() }}</div>
        @endif
    </div>
</x-core::layout>
