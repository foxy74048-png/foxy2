{{-- Colunas no tempo (uma série). $points: label, full, revenue, profit, orders. $caption: o que o gráfico mostra. --}}
@php
    $max = max(1, collect($points)->max('revenue'));
    // Teto "redondo" para a escala ficar fácil de ler.
    $magnitude = 10 ** max(0, strlen((string) intdiv($max, 100)) - 1) * 100;
    $top = (int) (ceil($max / $magnitude) * $magnitude);
    $every = max(1, (int) ceil(count($points) / 12));
    $money = fn ($c) => \App\Commerce\Catalog\Money::format($c);
    $short = fn ($c) => $c >= 100000000 ? 'R$ '.number_format($c / 100000000, 1, ',', '.').' mi' : ($c >= 100000 ? 'R$ '.number_format($c / 100000, 0, ',', '.').' mil' : $money($c));
@endphp
<div class="viz viz-cols" role="img" aria-label="{{ $caption }}">
    <div class="viz-cols__axis" aria-hidden="true">
        @foreach ([0, 0.5, 1] as $f)<span style="bottom: {{ $f * 100 }}%">{{ $short((int) ($top * $f)) }}</span>@endforeach
    </div>
    <div class="viz-cols__plot">
        @foreach ([0.5, 1] as $f)<div class="viz-cols__grid" style="bottom: {{ $f * 100 }}%"></div>@endforeach
        @foreach ($points as $p)
            <div class="viz-col" tabindex="0" data-tip-title="{{ $money($p['revenue']) }}" data-tip="{{ $p['full'] }}|{{ $p['orders'] }} {{ $p['orders'] === 1 ? 'pedido' : 'pedidos' }}|Lucro bruto: {{ $money($p['profit']) }}">
                <i style="height: {{ round($p['revenue'] / $top * 100, 2) }}%"></i>
            </div>
        @endforeach
    </div>
    <div class="viz-cols__labels" aria-hidden="true">
        @foreach ($points as $i => $p)<span>{{ $i % $every === 0 ? $p['label'] : '' }}</span>@endforeach
    </div>
</div>
<details class="viz-table">
    <summary>Ver números</summary>
    <div class="table-wrap" style="margin-top:8px;"><table>
        <thead><tr><th>Período</th><th>Pedidos</th><th>Faturamento</th><th>Lucro bruto</th></tr></thead>
        <tbody>@foreach ($points as $p)<tr><td>{{ $p['full'] }}</td><td>{{ $p['orders'] }}</td><td>{{ $money($p['revenue']) }}</td><td>{{ $money($p['profit']) }}</td></tr>@endforeach</tbody>
    </table></div>
</details>
