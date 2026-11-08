{{-- Barras horizontais (uma série). $rows: label, value (centavos), note (texto embaixo do valor), tip (linhas com |). --}}
@php($max = max(1, collect($rows)->max('value')))
@if (collect($rows)->isEmpty())
    <div class="empty">Sem vendas no período.</div>
@else
    <div class="viz viz-hbars">
        @foreach ($rows as $r)
            <div class="viz-hbar" tabindex="0" data-tip-title="{{ $r['label'] }}" data-tip="{{ $r['tip'] ?? '' }}">
                <div class="viz-hbar__label">{{ $r['label'] }}</div>
                <div class="viz-hbar__track"><i style="width: {{ max(0, round($r['value'] / $max * 100, 2)) }}%"></i></div>
                <div class="viz-hbar__value">{{ \App\Commerce\Catalog\Money::format($r['value']) }}@if (! empty($r['note']))<small>{{ $r['note'] }}</small>@endif</div>
            </div>
        @endforeach
    </div>
@endif
