<x-core::layout title="Pedidos" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:head>@include('commerce.charts.assets')</x-slot:head>
    <x-slot:actions>
        @include('commerce.export.buttons')
        @include('commerce._add', ['label' => 'Novo pedido', 'href' => route('commerce.orders.create')])
    </x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    <form class="card filters-row" method="GET" action="{{ route('commerce.orders') }}">
        <div>
            <label for="periodo">Período</label>
            <select id="periodo" name="periodo">@foreach (\App\Commerce\Insights\FinanceReport::PERIODS as $key => $label)<option value="{{ $key }}" @selected(($filters['periodo'] ?? '90d') === $key)>{{ $label }}</option>@endforeach</select>
        </div>
        <div><label for="de">De</label><input id="de" type="date" name="de" value="{{ $filters['de'] ?? '' }}"></div>
        <div><label for="ate">Até</label><input id="ate" type="date" name="ate" value="{{ $filters['ate'] ?? '' }}"></div>
        <div>
            <label for="status">Situação</label>
            <select id="status" name="status"><option value="">Todas</option>@foreach (\App\Commerce\Orders\Order::STATUSES as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach</select>
        </div>
        <div>
            <label for="canal">Canal</label>
            <select id="canal" name="canal"><option value="">Todos</option>@foreach (\App\Commerce\Orders\Order::CHANNELS as $key => $label)<option value="{{ $key }}" @selected(($filters['canal'] ?? '') === $key)>{{ $label }}</option>@endforeach</select>
        </div>
        <div>
            <label for="nota">Nota fiscal</label>
            <select id="nota" name="nota"><option value="">Todas</option><option value="sem" @selected(($filters['nota'] ?? '') === 'sem')>Vendidos sem nota</option><option value="com" @selected(($filters['nota'] ?? '') === 'com')>Com nota</option></select>
        </div>
        <div><label for="busca">Nº, cliente ou telefone</label><input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}"></div>
        <div class="actions"><button type="submit">Filtrar</button><a class="button secondary" href="{{ route('commerce.orders') }}">Limpar</a></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($orders->isEmpty())
            <div class="empty">Nenhum pedido com esses filtros. Venda no balcão ou no WhatsApp? Use <a href="{{ route('commerce.orders.create') }}">Novo pedido</a>.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Nº</th><th>Data</th><th>Canal</th><th>Cliente</th><th>Situação</th><th style="text-align:right;">Total</th><th style="text-align:right;">Lucro bruto</th></tr></thead>
                <tbody>
                    @foreach ($orders as $o)
                        <tr>
                            <td><a href="{{ route('commerce.orders.show', $o) }}"><strong>#{{ $o->number }}</strong></a></td>
                            <td>{{ $o->placed_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $o->channelLabel() }}</td>
                            <td>{{ $o->customer?->name ?? '—' }}</td>
                            <td><span @class(['badge', 'success' => in_array($o->status, ['completed', 'shipped'], true), 'danger' => $o->status === 'canceled'])>{{ $o->statusLabel() }}</span></td>
                            <td style="text-align:right;">{{ $money($o->total_cents) }}</td>
                            <td style="text-align:right;">{{ $o->status === 'canceled' ? '—' : $money($o->profitCents()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $orders->links() }}</div>
        @endif
    </div>
</x-core::layout>
