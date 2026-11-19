{{-- Uma linha de orçamento: categoria, gasto de limite e a barra (roxa; laranja a partir de 80%; vermelha ao estourar). $b vem de Indicators::budgets(). --}}
@php($color = $b['status'] === 'estourou' ? 'var(--danger)' : ($b['status'] === 'perto' ? 'var(--warning)' : 'var(--primary)'))
<div>
    <div style="display:flex;justify-content:space-between;gap:8px;">
        <span>{{ $b['category']->fullName() }}</span>
        <span style="white-space:nowrap;"><strong>{{ \App\Platform\Support\Money::format($b['spent']) }}</strong> <span class="muted">de {{ \App\Platform\Support\Money::format($b['limit']) }}</span></span>
    </div>
    <div style="height:8px;border-radius:4px;background:var(--border);margin-top:4px;" role="progressbar" aria-valuenow="{{ round($b['pct'] * 100) }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $b['category']->name }}"><div style="height:8px;border-radius:4px;background:{{ $color }};width:{{ max(2, min(100, round($b['pct'] * 100))) }}%;"></div></div>
    <div class="helper" @if ($b['status'] !== 'ok') style="color:{{ $b['status'] === 'estourou' ? 'var(--danger-text)' : 'var(--warning)' }};" @endif>
        {{ round($b['pct'] * 100) }}% usado ·
        @if ($b['status'] === 'estourou') passou {{ \App\Platform\Support\Money::format($b['spent'] - $b['limit']) }} do limite
        @else sobram {{ \App\Platform\Support\Money::format($b['limit'] - $b['spent']) }}
        @endif
    </div>
</div>
