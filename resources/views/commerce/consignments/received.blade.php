<x-core::layout title="Consignação" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce._add', ['label' => 'Importar comprovante ou nota', 'href' => route('commerce.supply').'#importar'])</x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')
    @include('commerce.consignments._tabs', ['tab' => 'recebidas'])

    @if ($rows->isEmpty())
        <div class="card empty">
            <p style="margin-top:0;">Nenhuma peça consignada de fornecedora em aberto.</p>
            <p class="helper">Quando chegar mercadoria em consignação, importe o comprovante ou a nota em Estoque › Remessas. Aqui você acompanha, até o acerto, quanto já vendeu, quanto já deve à fornecedora, o seu lucro e o que está parado.</p>
        </div>
    @else
        <div class="grid cards">
            <div class="card"><div class="muted">Vendido até hoje</div><h2 style="margin:4px 0;">{{ $rows->sum('sold') }} de {{ $rows->sum('pieces') }} peças</h2><div class="helper">{{ $money($rows->sum('revenue')) }} pelo seu preço de venda</div></div>
            <div class="card"><div class="muted">Já deve às fornecedoras</div><h2 style="margin:4px 0;">{{ $money($rows->sum('owed')) }}</h2><div class="helper">o vendido pelo custo; vira conta a pagar no acerto</div></div>
            <div class="card"><div class="muted">Seu lucro no consignado</div><h2 style="margin:4px 0;">{{ $money($rows->sum('profit')) }}</h2><div class="helper">venda − o que vai pagar</div></div>
        </div>
        <p class="helper">Vendido = o que veio menos o que você ainda tem no estoque de cada peça (se a mesma peça veio de outra fornecedora, confira no acerto).</p>

        @if ($suppliers->count() > 1)
            <div class="card" style="margin-top:16px;">
                <h3 style="margin-top:0;">Por fornecedora</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th>Fornecedora</th><th style="text-align:right;">Vendido</th><th style="text-align:right;">Venda</th><th style="text-align:right;">Já deve</th><th style="text-align:right;">Lucro</th></tr></thead>
                    <tbody>@foreach ($suppliers as $name => $s)<tr><td>{{ $name }}</td><td style="text-align:right;">{{ $s['sold'] }} de {{ $s['pieces'] }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($s['revenue']) }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($s['owed']) }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($s['profit']) }}</td></tr>@endforeach</tbody>
                </table></div>
            </div>
        @endif

        @foreach ($rows as $r)
            <div class="card" style="margin-top:16px;">
                <div style="display:flex;gap:12px;flex-wrap:wrap;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 style="margin:0;">{{ $r['batch']->label() }}</h3>
                        <div class="helper">recebida em {{ $r['batch']->received_on?->format('d/m/Y') }} · acerto {{ $r['batch']->closing_on?->format('d/m/Y') ?? 'sem data' }}
                            @if ($r['days'] !== null) · <span @if ($r['days'] <= 7) style="color:var(--danger-text);" @endif>{{ $r['days'] < 0 ? 'atrasado há '.abs($r['days']).' dia(s)' : ($r['days'] === 0 ? 'é hoje' : 'faltam '.$r['days'].' dia(s)') }}</span>@endif</div>
                    </div>
                    <a class="button secondary" href="{{ route('commerce.supply.show', $r['batch']) }}">Abrir / fazer o acerto</a>
                </div>
                <div style="margin:12px 0 4px;background:var(--border);border-radius:999px;height:10px;overflow:hidden;" role="img" aria-label="{{ $r['rate'] }}% vendido"><div style="width:{{ $r['rate'] }}%;height:100%;background:var(--primary);"></div></div>
                <div class="helper">{{ $r['rate'] }}% vendido · {{ $r['sold'] }} de {{ $r['pieces'] }} peças · venda {{ $money($r['revenue']) }} · já deve {{ $money($r['owed']) }} · lucro {{ $money($r['profit']) }}</div>
                @if ($r['stuck']->isNotEmpty())
                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;">{{ $r['stuck']->count() }} peça(s) sem nenhuma venda — vale divulgar ou separar para devolver</summary>
                        <ul class="helper" style="margin:8px 0 0;padding-left:18px;">@foreach ($r['stuck'] as $i)<li>{{ $i['item']->variant?->fullName() ?? $i['item']->description }} ({{ $i['has'] }})</li>@endforeach</ul>
                    </details>
                @endif
            </div>
        @endforeach
    @endif
</x-core::layout>
