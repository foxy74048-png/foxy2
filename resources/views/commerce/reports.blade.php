<x-core::layout title="Relatórios" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:head>@include('commerce.charts.assets')</x-slot:head>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($pct = fn ($v) => $v === null ? '—' : ($v >= 0 ? '▲ ' : '▼ ').number_format(abs($v), 1, ',', '.').'%')

    @include('commerce.status')

    {{-- Abas sempre à vista --}}
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
        @foreach (\App\Commerce\Http\ReportController::TABS as $key => $label)
            <a href="{{ route('commerce.reports', ['aba' => $key]) }}" @class(['button', 'secondary' => $tab !== $key])>{{ $label }}</a>
        @endforeach
    </div>

    @if ($tab === 'mensal')
        <form class="card filters-row" method="GET" action="{{ route('commerce.reports') }}">
            <input type="hidden" name="aba" value="mensal">
            <div><label for="meses">Meses</label><select id="meses" name="meses">@foreach ([6, 12, 24] as $m)<option value="{{ $m }}" @selected((int) ($filters['meses'] ?? 12) === $m)>Últimos {{ $m }}</option>@endforeach</select></div>
            <div><label for="canal">Canal</label><select id="canal" name="canal"><option value="">Todos</option>@foreach (\App\Commerce\Orders\Order::CHANNELS as $k => $l)<option value="{{ $k }}" @selected(($filters['canal'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="actions"><button type="submit">Aplicar</button></div>
        </form>

        <div class="card" style="margin-top:16px;">
            <div class="section-title"><h3>Faturamento por mês</h3><span class="helper">o mês atual vai até hoje</span></div>
            @include('commerce.charts.columns', ['points' => $months->all(), 'caption' => 'Faturamento mês a mês'])
        </div>

        <div class="card" style="margin-top:16px;">
            <div class="table-wrap"><table>
                <thead><tr><th>Mês</th><th class="num">Faturamento</th><th class="num">vs. mês anterior</th><th class="num">Pedidos</th><th class="num">Ticket médio</th><th class="num">Lucro bruto</th><th class="num">Margem</th></tr></thead>
                <tbody>
                    @foreach ($months->reverse() as $m)
                        <tr>
                            <td>{{ $m['full'] }}</td>
                            <td class="num">{{ $money($m['revenue']) }}</td>
                            <td class="num"><span @class(['kpi__delta', 'up' => ($m['delta'] ?? 0) >= 0 && $m['delta'] !== null, 'down' => ($m['delta'] ?? 0) < 0])>{{ $pct($m['delta']) }}</span>@if ($m['current'])<div class="helper">vs. os mesmos dias do mês passado</div>@endif</td>
                            <td class="num">{{ $m['orders'] }}</td>
                            <td class="num">{{ $money($m['ticket']) }}</td>
                            <td class="num">{{ $money($m['profit']) }}</td>
                            <td class="num">{{ number_format($m['margin'], 1, ',', '.') }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @elseif ($tab === 'estoque')
        @php($statusLabels = \App\Commerce\Http\ReportController::STOCK_STATUS)
        <div class="kpis">
            <div class="card kpi"><div class="kpi__label">Dinheiro parado</div><div class="kpi__value">{{ $money($summary['stuck']) }}</div><div class="kpi__note">em itens parados ou lentos, pelo custo</div></div>
            <div class="card kpi"><div class="kpi__label">Estoque total (custo)</div><div class="kpi__value">{{ $money($summary['total']) }}</div><div class="kpi__note">preencha o custo das variantes para este número valer</div></div>
            @foreach (['parado', 'lento', 'acabando'] as $s)
                <a class="card kpi" href="{{ route('commerce.reports', ['aba' => 'estoque', 'situacao' => $s, 'dias' => $filters['dias'] ?? 90]) }}"><div class="kpi__label">{{ $statusLabels[$s] }}</div><div class="kpi__value">{{ $summary['counts'][$s] ?? 0 }}</div><div class="kpi__note">variantes</div></a>
            @endforeach
        </div>

        <form class="card filters-row" method="GET" action="{{ route('commerce.reports') }}" style="margin-top:16px;">
            <input type="hidden" name="aba" value="estoque">
            <div><label for="dias">Vendas dos últimos</label><select id="dias" name="dias">@foreach ([30, 60, 90, 180] as $d)<option value="{{ $d }}" @selected((int) ($filters['dias'] ?? 90) === $d)>{{ $d }} dias</option>@endforeach</select></div>
            <div><label for="situacao">Situação</label><select id="situacao" name="situacao"><option value="">Todas</option>@foreach ($statusLabels as $k => $l)<option value="{{ $k }}" @selected(($filters['situacao'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="actions"><button type="submit">Aplicar</button><a class="button secondary" href="{{ route('commerce.reports.stock-csv', ['dias' => $filters['dias'] ?? 90]) }}">Baixar planilha</a></div>
        </form>

        <div class="card" style="margin-top:16px;">
            @if ($rows->isEmpty())
                <div class="empty">Nada com esses filtros.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th>SKU</th><th>Produto</th><th class="num">Físico</th><th class="num">Vendido</th><th class="num">Dura</th><th>Última venda</th><th class="num">Parado (custo)</th><th>Situação</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td><a href="{{ route('commerce.stock.show', $r['variant']) }}">{{ $r['variant']->sku }}</a></td>
                                <td>{{ $r['variant']->fullName() }}</td>
                                <td class="num">{{ $r['on_hand'] }}</td>
                                <td class="num">{{ $r['sold'] }}</td>
                                <td class="num">{{ $r['coverage'] === null ? '—' : $r['coverage'].' dias' }}</td>
                                <td>{{ $r['last_sale']?->format('d/m/Y') ?? 'nunca' }}</td>
                                <td class="num">{{ $money($r['stuck_cents']) }}</td>
                                <td><span @class(['badge', 'danger' => in_array($r['status'], ['parado', 'sem_estoque'], true), 'success' => $r['status'] === 'saudavel'])>{{ \Illuminate\Support\Str::before($statusLabels[$r['status']], ' (') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="helper">"Dura" = físico ÷ média de vendas por dia no período. Lento: dura mais de 4 meses. Acabando: menos de 15 dias.</p>
            @endif
        </div>
    @else
        <form class="card filters-row" method="GET" action="{{ route('commerce.reports') }}">
            <input type="hidden" name="aba" value="abc">
            <div><label for="periodo">Período</label><select id="periodo" name="periodo">@foreach (\App\Commerce\Insights\FinanceReport::PERIODS as $k => $l)<option value="{{ $k }}" @selected(($filters['periodo'] ?? '90d') === $k)>{{ $l }}</option>@endforeach</select></div>
            <div><label for="de">De</label><input id="de" type="date" name="de" value="{{ $filters['de'] ?? $from->format('Y-m-d') }}"></div>
            <div><label for="ate">Até</label><input id="ate" type="date" name="ate" value="{{ $filters['ate'] ?? $to->format('Y-m-d') }}"></div>
            <div><label for="classe">Classe</label><select id="classe" name="classe"><option value="">Todas</option>@foreach (['A', 'B', 'C'] as $c)<option value="{{ $c }}" @selected(($filters['classe'] ?? '') === $c)>{{ $c }}</option>@endforeach</select></div>
            <div class="actions"><button type="submit">Aplicar</button></div>
        </form>

        <div class="kpis" style="margin-top:16px;">
            @foreach (['A' => 'os que fazem 80% do faturamento: nunca podem faltar', 'B' => 'os próximos 15%: acompanhe', 'C' => 'os últimos 5%: candidatos a promoção ou corte'] as $c => $hint)
                <a class="card kpi" href="{{ route('commerce.reports', array_filter(['aba' => 'abc', 'classe' => $c, 'periodo' => $filters['periodo'] ?? null])) }}">
                    <div class="kpi__label">Classe {{ $c }}</div>
                    <div class="kpi__value">{{ $classes[$c]['count'] ?? 0 }} produto(s)</div>
                    <div class="kpi__note">{{ $money($classes[$c]['revenue'] ?? 0) }} · {{ $hint }}</div>
                </a>
            @endforeach
        </div>

        <div class="card" style="margin-top:16px;">
            @if ($rows->isEmpty())
                <div class="empty">Sem vendas no período.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th>Classe</th><th>Produto</th><th class="num">Qtd.</th><th class="num">Faturamento</th><th class="num">% do total</th><th class="num">Acumulado</th><th class="num">Margem</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td><span @class(['badge', 'success' => $r['class'] === 'A', 'danger' => $r['class'] === 'C'])>{{ $r['class'] }}</span></td>
                                <td>{{ $r['label'] }}</td>
                                <td class="num">{{ $r['quantity'] }}</td>
                                <td class="num">{{ $money($r['revenue']) }}</td>
                                <td class="num">{{ number_format($r['share'], 1, ',', '.') }}%</td>
                                <td class="num">{{ number_format($r['cumulative'], 1, ',', '.') }}%</td>
                                <td class="num">{{ number_format($r['margin'], 1, ',', '.') }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="helper">De {{ $from->format('d/m/Y') }} a {{ $to->format('d/m/Y') }}. Margem do produto = (faturamento − custo) ÷ faturamento, sem taxas dos canais.</p>
            @endif
        </div>
    @endif
</x-core::layout>
