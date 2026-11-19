<x-core::layout title="Onde vai o dinheiro" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:head>@include('platform.charts.assets')</x-slot:head>
    <x-slot:actions>@include('platform.export-buttons')</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($pct = fn (?float $v) => $v === null ? '—' : number_format($v * 100, 0, ',', '.').'%')
    @php($s = $summary)
    @php($isCurrent = $month->isSameMonth(today()))

    @include('finance.status')

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <a class="button secondary" href="{{ route('finance.insights', ['mes' => $neighbours[0]]) }}" aria-label="Mês anterior">‹</a>
        <h2 style="margin:0;font-size:1.2rem;">{{ \Illuminate\Support\Str::ucfirst(\App\Finance\Transactions\TransactionFilters::monthName($month)) }}</h2>
        <a class="button secondary" href="{{ route('finance.insights', ['mes' => $neighbours[1]]) }}" aria-label="Mês seguinte">›</a>
        @unless ($isCurrent)<a class="helper" href="{{ route('finance.insights') }}">voltar para este mês</a>@endunless
    </div>

    {{-- O mês em números --}}
    <div class="kpis">
        <div class="card kpi"><div class="kpi__label">Entrou</div><div class="kpi__value" style="color:var(--success-text);">{{ $money($s['income']) }}</div></div>
        <div class="card kpi"><div class="kpi__label">Saiu</div><div class="kpi__value">{{ $money($s['expense']) }}</div>@if ($s['avg_expense'])<div class="kpi__note">média dos 3 meses antes: {{ $money($s['avg_expense']) }}</div>@endif</div>
        <div class="card kpi">
            <div class="kpi__label">Guardou da renda</div>
            <div class="kpi__value" @if (($s['savings_rate'] ?? 0) < 0) style="color:var(--danger-text);" @endif>{{ $pct($s['savings_rate']) }}</div>
            <div class="kpi__note">sobrou {{ $money($s['saved']) }}@if ($s['avg_savings_rate'] !== null) · antes: <span @class(['kpi__delta', 'up' => $s['savings_rate'] !== null && $s['savings_rate'] >= $s['avg_savings_rate'], 'down' => $s['savings_rate'] !== null && $s['savings_rate'] < $s['avg_savings_rate']])>{{ $pct($s['avg_savings_rate']) }}</span>@endif</div>
        </div>
        @if ($pace)
            <div class="card kpi">
                <div class="kpi__label">No ritmo atual, o mês fecha em</div>
                <div class="kpi__value">{{ $money($pace['projected']) }}</div>
                <div class="kpi__note">{{ $money($pace['per_day']) }} por dia · faltam {{ $pace['days_left'] }} dia(s)@if ($pace['planned']) · {{ $money($pace['planned']) }} já previsto @endif</div>
            </div>
        @endif
    </div>

    {{-- A raposa sobre o mês (fatia 8): escrita a partir dos números acima, guardada; refazer gasta crédito --}}
    @if ($foxReady)
        <div class="card" style="margin-top:16px;">
            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;">
                <h3 style="margin:0;">A raposa sobre o seu mês</h3>
                <form method="POST" action="{{ route('finance.fox.tips') }}">@csrf<input type="hidden" name="mes" value="{{ $month->format('Y-m') }}"><button type="submit" class="secondary">{{ $tips ? 'Escrever de novo' : 'Pedir as dicas da raposa' }}</button></form>
            </div>
            @if ($tips)
                <ul style="margin:10px 0 0;padding-left:20px;line-height:1.7;">@foreach ($tips->content['sentences'] ?? [] as $sentence)<li>{{ $sentence }}</li>@endforeach</ul>
                @if (! empty($tips->content['suggestion']))<p style="margin:10px 0 0;"><strong>Sugestão:</strong> {{ $tips->content['suggestion'] }}</p>@endif
                <p class="helper" style="margin:8px 0 0;">Escrito em {{ $tips->updated_at->timezone('America/Sao_Paulo')->format('d/m H:i') }} a partir dos números desta tela (a raposa não faz contas).</p>
            @else
                <p class="helper" style="margin:8px 0 0;">A raposa lê os números desta tela e escreve 3 frases e uma sugestão para o próximo mês. Vai só o resumo (nenhum lançamento, conta ou nome). Gasta 1 crédito.</p>
            @endif
        </div>
    @endif

    {{-- Dá para economizar: o que a pessoa pode fazer, sempre à vista --}}
    <div class="card" style="margin-top:16px;border-color:var(--border-strong);">
        <h3 style="margin-top:0;">Onde dá para economizar</h3>
        <div class="grid cards">
            <div>
                <div class="muted">Se cortar pela metade</div>
                @if ($cut['categories']->isEmpty())
                    <div class="helper">Ainda sem gastos suficientes fora do essencial para calcular.</div>
                @else
                    <h2 style="margin:4px 0;color:var(--success-text);">{{ $money($cut['per_year']) }} por ano</h2>
                    <div class="helper">gastando a metade em {{ $cut['categories']->pluck('name')->join(', ', ' e ') }} (hoje cerca de {{ $money($cut['categories']->sum('base')) }} por mês)</div>
                @endif
            </div>
            <div>
                <div class="muted">Gastinhos (compras até R$ 30)</div>
                <h2 style="margin:4px 0;">{{ $money($small['total']) }}</h2>
                <div class="helper">{{ $small['count'] }} compra(s) no mês · nesse ritmo, <strong>{{ $money($small['per_year']) }} por ano</strong>@if ($small['top']->isNotEmpty()) · mais em {{ $small['top']->pluck('label')->take(2)->join(' e ') }}@endif</div>
            </div>
            <div>
                <div class="muted">Assinaturas</div>
                <h2 style="margin:4px 0;">{{ $money($subscriptions->sum('monthly')) }} por mês</h2>
                <div class="helper">{{ $subscriptions->count() }} encontrada(s) · {{ $money($subscriptions->sum('yearly')) }} por ano. Veja abaixo se ainda usa todas.</div>
            </div>
            <div>
                <div class="muted">Reserva de emergência</div>
                @if (! $reserve['has_savings_account'])
                    <div class="helper">Cadastre a sua poupança (ou onde guarda dinheiro) em <a href="{{ route('finance.accounts') }}#nova-conta">Contas</a> para ver quantos meses ela cobre.</div>
                @elseif ($reserve['months'] === null)
                    <h2 style="margin:4px 0;">{{ $money($reserve['saved']) }}</h2><div class="helper">guardado</div>
                @else
                    <h2 style="margin:4px 0;{{ $reserve['months'] < 3 ? 'color:var(--warning);' : 'color:var(--success-text);' }}">{{ number_format($reserve['months'], 1, ',', '.') }} {{ $reserve['months'] >= 1 && $reserve['months'] < 2 ? 'mês' : 'meses' }}</h2>
                    <div class="helper">{{ $money($reserve['saved']) }} guardado ÷ {{ $money($reserve['avg_expense']) }} de gasto por mês. O recomendado é ter de 3 a 6 meses.</div>
                @endif
            </div>
        </div>
    </div>

    <div class="grid two" style="align-items:start;margin-top:16px;">
        <div class="card">
            <h3 style="margin:0;">Por categoria</h3>
            <p class="helper" style="margin:4px 0 14px;">a barra é o mês{{ $hasHistory ? '; embaixo, a média dos 3 meses antes' : '' }}</p>
            @include('platform.charts.hbars', ['empty' => 'Nenhum gasto neste mês.', 'rows' => $categories->filter(fn ($c) => $c['total'] > 0)->take(8)->map(fn ($c) => [
                'label' => $c['name'], 'value' => $c['total'],
                'note' => ! $hasHistory ? '' : ($c['avg'] ? 'média '.$money($c['avg']) : 'novo'),
                'tip' => 'Média dos 3 meses antes: '.$money($c['avg']).'|Diferença: '.($c['delta'] >= 0 ? '+' : '−').$money(abs($c['delta'])),
                'href' => route('finance.transactions', array_filter(['mes' => $month->format('Y-m'), 'categoria' => $c['id'], 'tipo' => 'gasto'])),
            ])->values()])
        </div>
        <div class="card">
            <div class="section-title"><h3>Onde mais gastou</h3><span class="helper">por lugar</span></div>
            @include('platform.charts.hbars', ['empty' => 'Nenhum gasto neste mês.', 'rows' => $places->map(fn ($p) => [
                'label' => $p['label'], 'value' => $p['total'], 'note' => $p['count'].' vez(es)', 'tip' => $p['count'].' compra(s)',
            ])])
        </div>
    </div>

    <div class="grid two" style="align-items:start;margin-top:16px;">
        <div class="card">
            <h3 style="margin-top:0;">O que mais subiu</h3>
            @if ($rises->isEmpty())
                <div class="empty">{{ $hasHistory ? 'Nada subiu muito em relação aos 3 meses anteriores.' : 'Ainda não há meses anteriores para comparar. Importe extratos antigos para ver isso desde já.' }}</div>
            @else
                <div class="stack">
                    @foreach ($rises as $r)
                        <div style="display:flex;justify-content:space-between;gap:8px;">
                            <span><strong>{{ $r['name'] }}</strong><div class="helper">{{ $r['avg'] ? 'média de '.$money($r['avg']).' → '.$money($r['total']) : 'novo neste mês' }}</div></span>
                            <strong style="color:var(--danger-text);white-space:nowrap;">+ {{ $money($r['delta']) }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="card">
            <div class="section-title"><h3>Orçamento</h3><a class="helper" href="{{ route('finance.budgets', ['mes' => $month->format('Y-m')]) }}">ver e ajustar</a></div>
            @if ($budgets->isEmpty())
                <div class="empty">Nenhum limite ainda. <a href="{{ route('finance.budgets') }}">Definir um limite por categoria</a> ajuda a não passar do ponto.</div>
            @else
                <div class="stack">
                    @foreach ($budgets->take(5) as $b)
                        @include('finance._budget-bar', ['b' => $b])
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Assinaturas</h3>
        <p class="helper" style="margin-top:0;">O que se repete todo mês e dá para cancelar (contas do essencial, como aluguel e luz, ficam de fora).</p>
        @if ($subscriptions->isEmpty())
            <div class="empty">Nenhuma encontrada. Aparecem aqui o que foi lançado como "todo mês" e o mesmo lugar com valor parecido em 3 dos últimos 4 meses.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>O quê</th><th>Categoria</th><th class="num">Por mês</th><th class="num">Por ano</th></tr></thead>
                <tbody>@foreach ($subscriptions as $x)<tr><td><strong>{{ $x['label'] }}</strong></td><td>{{ $x['category'] ?? '—' }}</td><td class="num">{{ $money($x['monthly']) }}</td><td class="num">{{ $money($x['yearly']) }}</td></tr>@endforeach</tbody>
            </table></div>
        @endif
    </div>

    <div class="card" style="margin-top:16px;">
        <div class="section-title"><h3>Mês a mês</h3><span class="helper">quanto saiu em cada mês (passe o mouse para ver entrou e sobrou)</span></div>
        @php($max = max(1, $evolution->max('expense'), $evolution->max('income')))
        <div class="viz viz-cols" role="img" aria-label="Quanto saiu por mês">
            <div class="viz-cols__axis" aria-hidden="true">@foreach ([0, 0.5, 1] as $f)<span style="bottom: {{ $f * 100 }}%">{{ $money((int) ($max * $f)) }}</span>@endforeach</div>
            <div class="viz-cols__plot">
                @foreach ([0.5, 1] as $f)<div class="viz-cols__grid" style="bottom: {{ $f * 100 }}%"></div>@endforeach
                @foreach ($evolution as $e)
                    <div class="viz-col" tabindex="0" data-tip-title="Saiu {{ $money($e['expense']) }}" data-tip="{{ $e['full'] }}|Entrou {{ $money($e['income']) }}|Sobrou {{ $money($e['saved']) }}"><i style="height: {{ round($e['expense'] / $max * 100, 2) }}%"></i></div>
                @endforeach
            </div>
            <div class="viz-cols__labels" aria-hidden="true">@foreach ($evolution as $e)<span>{{ $e['label'] }}</span>@endforeach</div>
        </div>
        <div class="table-wrap" style="margin-top:12px;"><table>
            <thead><tr><th>Mês</th><th class="num">Entrou</th><th class="num">Saiu</th><th class="num">Sobrou</th></tr></thead>
            <tbody>@foreach ($evolution as $e)<tr><td>{{ $e['full'] }}</td><td class="num">{{ $money($e['income']) }}</td><td class="num">{{ $money($e['expense']) }}</td><td class="num" @if ($e['saved'] < 0) style="color:var(--danger-text);" @endif>{{ $money($e['saved']) }}</td></tr>@endforeach</tbody>
        </table></div>
    </div>
</x-core::layout>
