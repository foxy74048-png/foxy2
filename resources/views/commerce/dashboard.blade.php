<x-core::layout title="Painel" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@if ($finance)@include('commerce.export.buttons')@endif</x-slot:actions>
    <x-slot:head>@include('commerce.charts.assets')</x-slot:head>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($can = fn ($p) => \App\Commerce\Access::allows($currentMembership, $p))

    @include('commerce.status')

    {{-- Atalhos: o que dá para lançar, cada um conforme a permissão --}}
    @php($shortcuts = array_filter([
        'Novo pedido' => $can('commerce.orders.manage') ? route('commerce.orders.create') : null,
        'Novo produto' => $can('commerce.catalog.manage') ? route('commerce.products.create') : null,
        'Nova cliente' => $can('commerce.orders.manage') ? route('commerce.customers').'#nova-cliente' : null,
        'Conta a receber' => $can('commerce.finance.manage') ? route('commerce.finance', ['aba' => 'receber']).'#nova-conta' : null,
        'Conta a pagar' => $can('commerce.finance.manage') ? route('commerce.finance', ['aba' => 'pagar']).'#nova-conta' : null,
        'Entrada de estoque' => $can('commerce.stock.adjust') ? route('commerce.stock', ['aba' => 'entrada']) : null,
        'Remessa da fornecedora' => $can('commerce.catalog.manage') ? route('commerce.supply') : null,
    ]))
    @if ($shortcuts)
        <div class="card" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <strong style="margin-right:4px;">Lançar:</strong>
            @foreach ($shortcuts as $label => $url)
                <a @class(['button', 'secondary' => ! $loop->first]) href="{{ $url }}">+ {{ $label }}</a>
            @endforeach
        </div>
    @endif

    {{-- Dinheiro que pede ação: atrasos, vencimentos da semana e acertos de consignação --}}
    @if ($alerts)
        @php($fmt = fn ($c) => \App\Commerce\Catalog\Money::format($c))
        <div class="grid cards" style="margin-bottom:16px;">
            <a class="card" href="{{ route('commerce.finance', ['situacao' => 'vencidas']) }}"><div class="muted">Clientes com conta vencida</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => $alerts['overdue'] > 0])>{{ $fmt($alerts['overdue']) }}</h2><div class="helper">{{ $alerts['overdue_count'] }} conta(s) · cobrar pelo WhatsApp</div></a>
            <a class="card" href="{{ route('commerce.finance', ['de' => today()->toDateString(), 'ate' => today()->addDays(7)->toDateString()]) }}"><div class="muted">A receber em 7 dias</div><h2 style="margin:4px 0;">{{ $fmt($alerts['receive_week']) }}</h2></a>
            <a class="card" href="{{ route('commerce.finance', ['aba' => 'pagar', 'ate' => today()->addDays(7)->toDateString()]) }}"><div class="muted">A pagar até 7 dias</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => $alerts['pay_overdue'] > 0])>{{ $fmt($alerts['pay_week']) }}</h2><div class="helper">{{ $alerts['pay_overdue'] ? $alerts['pay_overdue'].' já vencida(s)' : 'em dia' }}</div></a>
            @foreach ($alerts['settlements'] as $batch)
                <a class="card" href="{{ route('commerce.supply.show', $batch) }}" style="border-color:var(--danger);"><div class="muted">Acerto da consignação</div><h2 style="margin:4px 0;font-size:1.1rem;">{{ $batch->supplier }}</h2><div class="helper" style="color:var(--danger-text);">{{ $batch->closing_on->isPast() ? 'atrasado desde' : ($batch->closing_on->isToday() ? 'é hoje,' : 'em') }} {{ $batch->closing_on->format('d/m') }}</div></a>
            @endforeach
        </div>
    @endif

    {{-- Operação: o que pede ação agora --}}
    <div class="grid cards">
        @if ($can('commerce.orders.manage'))
            <a class="card" href="{{ route('commerce.orders', ['status' => 'paid']) }}"><div class="muted">Para separar e enviar</div><h2 style="margin:4px 0;">{{ $toShip }}</h2></a>
            <a class="card" href="{{ route('commerce.orders', ['status' => 'awaiting_payment']) }}"><div class="muted">Aguardando pagamento</div><h2 style="margin:4px 0;">{{ $awaiting }}</h2></a>
            <a class="card" href="{{ route('commerce.orders', ['nota' => 'sem', 'periodo' => '30d']) }}"><div class="muted">Vendas sem nota (30 dias)</div><h2 style="margin:4px 0;">{{ $withoutInvoice }}</h2></a>
        @endif
        @if ($can('commerce.stock.view'))
            <a class="card" href="{{ route('commerce.stock', ['filtro' => 'baixo']) }}"><div class="muted">Estoque baixo ou negativo</div><h2 @style(['margin:4px 0', 'color:var(--danger-text)' => $lowStock > 0])>{{ $lowStock }}</h2></a>
        @endif
        <div class="card">
            <div class="muted">Assinatura</div>
            <h2 style="margin:4px 0;font-size:1.1rem;">
                @if ($currentSubscription?->isFounder()) Parceiro Fundador
                @elseif ($currentSubscription?->isPaying()) {{ \App\Commerce\Billing\Plans::name($currentSubscription->plan) }}
                @elseif ($currentSubscription?->inTrial()) Teste grátis até {{ $currentSubscription->trial_ends_at->format('d/m') }}
                @else Só consulta
                @endif
            </h2>
            <div class="helper">{{ $teamCount }} {{ $teamCount === 1 ? 'pessoa' : 'pessoas' }} na equipe</div>
        </div>
    </div>

    @if ($finance)
        @php($k = $finance['kpis'])
        @php($r = $finance['report'])

        {{-- Filtros: uma linha, sempre visíveis --}}
        <form class="card filters-row" method="GET" action="{{ route('commerce.dashboard') }}" style="margin-top:20px;">
            <div>
                <label for="periodo">Período</label>
                <select id="periodo" name="periodo">
                    @foreach (\App\Commerce\Insights\FinanceReport::PERIODS as $key => $label)<option value="{{ $key }}" @selected(($filters['periodo'] ?? '30d') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div><label for="de">De</label><input id="de" type="date" name="de" value="{{ $filters['de'] ?? $r->from->format('Y-m-d') }}"></div>
            <div><label for="ate">Até</label><input id="ate" type="date" name="ate" value="{{ $filters['ate'] ?? $r->to->format('Y-m-d') }}"></div>
            <div>
                <label for="canal">Canal</label>
                <select id="canal" name="canal"><option value="">Todos</option>@foreach (\App\Commerce\Orders\Order::CHANNELS as $key => $label)<option value="{{ $key }}" @selected(($filters['canal'] ?? '') === $key)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="pagamento">Pagamento</label>
                <select id="pagamento" name="pagamento"><option value="">Todos</option>@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $key => $label)<option value="{{ $key }}" @selected(($filters['pagamento'] ?? '') === $key)>{{ $label }}</option>@endforeach</select>
            </div>
            <div>
                <label for="categoria">Categoria</label>
                <select id="categoria" name="categoria"><option value="">Todas</option>@foreach ($finance['categoryOptions'] as $c)<option value="{{ $c->id }}" @selected(($filters['categoria'] ?? '') === $c->id)>{{ $c->fullName() }}</option>@endforeach</select>
            </div>
            <div class="actions"><button type="submit">Aplicar</button><a class="button secondary" href="{{ route('commerce.dashboard') }}">Limpar</a></div>
        </form>
        <p class="helper" style="margin:8px 2px 0;">De {{ $r->from->format('d/m/Y') }} a {{ $r->to->format('d/m/Y') }}. As datas só valem com "Escolher datas". Comparação com o mesmo número de dias logo antes.</p>

        {{-- Indicadores --}}
        @php($delta = fn ($v) => $v === null ? '' : ($v >= 0 ? '▲ ' : '▼ ').number_format(abs($v), 1, ',', '.').'%')
        <div class="kpis" style="margin-top:14px;">
            @foreach ([
                ['Faturamento', $money($k['revenue']), $k['delta_revenue'], 'vendas pagas no período'],
                ['Pedidos', number_format($k['orders'], 0, ',', '.'), $k['delta_orders'], $k['new_customers'].' cliente(s) novo(s)'],
                ['Ticket médio', $money($k['ticket']), $k['delta_ticket'], 'por pedido'],
                ['Lucro bruto', $money($k['profit']), $k['delta_profit'], 'margem de '.number_format($k['margin'], 1, ',', '.').'%'],
            ] as [$label, $value, $d, $note])
                <div class="card kpi">
                    <div class="kpi__label">{{ $label }}</div>
                    <div class="kpi__value">{{ $value }}</div>
                    <div class="kpi__note">@if ($d !== null)<span @class(['kpi__delta', 'up' => $d >= 0, 'down' => $d < 0])>{{ $delta($d) }}</span> vs. antes · @endif{{ $note }}</div>
                </div>
            @endforeach
            @foreach ([
                ['Taxas dos canais', $money($k['fees']), 'comissões e tarifas dos marketplaces'],
                ['Custo das mercadorias', $money($k['cost']), 'pelo custo cadastrado das variantes'],
                ['A receber', $money($k['awaiting_cents']), $k['awaiting_count'].' pedido(s) aguardando pagamento'],
                ['Cancelamentos', $money($k['canceled_cents']), $k['canceled_count'].' pedido(s) · '.number_format($k['cancel_rate'], 1, ',', '.').'% dos pedidos'],
            ] as [$label, $value, $note])
                <div class="card kpi">
                    <div class="kpi__label">{{ $label }}</div>
                    <div class="kpi__value" style="font-size:1.3rem;">{{ $value }}</div>
                    <div class="kpi__note">{{ $note }}</div>
                </div>
            @endforeach
        </div>

        {{-- Faturamento no tempo --}}
        <div class="card" style="margin-top:16px;">
            <div class="section-title"><h3>Faturamento por {{ $finance['series']['unit'] }}</h3><span class="helper">passe o mouse nas colunas para ver pedidos e lucro</span></div>
            @include('commerce.charts.columns', ['points' => $finance['series']['points'], 'caption' => 'Faturamento por '.$finance['series']['unit'].' de '.$r->from->format('d/m/Y').' a '.$r->to->format('d/m/Y')])
        </div>

        <div class="grid two" style="margin-top:16px;">
            <div class="card">
                <div class="section-title"><h3>Por canal</h3><span class="helper">faturamento · margem</span></div>
                @include('commerce.charts.hbars', ['rows' => $finance['channels']->map(fn ($c) => [
                    'label' => $c['label'], 'value' => $c['revenue'],
                    'note' => $c['orders'].' pedidos · margem '.number_format($c['margin'], 1, ',', '.').'%',
                    'tip' => 'Faturamento: '.$money($c['revenue']).'|Taxas: '.$money($c['fees']).'|Lucro bruto: '.$money($c['profit']).'|Ticket médio: '.$money($c['ticket']),
                ])])
            </div>
            <div class="card">
                <div class="section-title"><h3>Formas de pagamento</h3><span class="helper">faturamento</span></div>
                @include('commerce.charts.hbars', ['rows' => $finance['payments']->map(fn ($p) => [
                    'label' => $p['label'], 'value' => $p['revenue'], 'note' => $p['orders'].' pedidos',
                    'tip' => $p['orders'].' pedidos|Ticket médio: '.$money($p['ticket']),
                ])])
            </div>
        </div>

        <div class="grid two" style="margin-top:16px;">
            <div class="card">
                <div class="section-title"><h3>Mais vendidos</h3><span class="helper">por faturamento</span></div>
                @if ($finance['products']->isEmpty())
                    <div class="empty">Sem vendas no período.</div>
                @else
                    <div class="table-wrap"><table>
                        <thead><tr><th>Produto</th><th style="text-align:right;">Qtd.</th><th style="text-align:right;">Faturamento</th><th style="text-align:right;">Lucro</th></tr></thead>
                        <tbody>@foreach ($finance['products'] as $p)<tr><td>{{ $p['label'] }}</td><td style="text-align:right;">{{ $p['quantity'] }}</td><td class="num">{{ $money($p['revenue']) }}</td><td class="num">{{ $money($p['profit']) }}</td></tr>@endforeach</tbody>
                    </table></div>
                @endif
            </div>
            <div class="card">
                <div class="section-title"><h3>Por categoria</h3><span class="helper">faturamento dos itens</span></div>
                @include('commerce.charts.hbars', ['rows' => $finance['categories']->map(fn ($c) => [
                    'label' => $c['label'], 'value' => $c['revenue'], 'note' => $c['quantity'].' itens', 'tip' => $c['quantity'].' itens vendidos',
                ])])
            </div>
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="section-title"><h3>Melhores clientes</h3><a href="{{ route('commerce.customers') }}">Ver todos os clientes</a></div>
            @if ($finance['customers']->isEmpty())
                <div class="empty">Sem clientes identificados no período (vendas de marketplace sem cadastro não entram aqui).</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th>Cliente</th><th style="text-align:right;">Pedidos</th><th style="text-align:right;">Total comprado</th></tr></thead>
                    <tbody>@foreach ($finance['customers'] as $c)<tr><td><a href="{{ route('commerce.customers.show', $c['id']) }}">{{ $c['label'] }}</a></td><td style="text-align:right;">{{ $c['orders'] }}</td><td style="text-align:right;">{{ $money($c['revenue']) }}</td></tr>@endforeach</tbody>
                </table></div>
            @endif
        </div>

        <p class="helper" style="margin-top:12px;">Lucro bruto = faturamento − frete cobrado − taxas dos canais − custo das mercadorias. Não inclui despesas fixas da loja. <a href="{{ route('commerce.orders.export', array_filter(['periodo' => $filters['periodo'] ?? '30d', 'de' => $filters['de'] ?? null, 'ate' => $filters['ate'] ?? null, 'canal' => $filters['canal'] ?? null])) }}">Baixar planilha dos pedidos do período</a></p>
    @endif
</x-core::layout>
