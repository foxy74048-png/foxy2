<x-core::layout title="Painel" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    @php
        $n = fn ($v, $d = 0) => number_format((float) $v, $d, ',', '.');
        $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    @endphp

    <div class="grid cards">
        <div class="card"><div class="muted">Faturamento mensal (MRR)</div><h2>{{ $brl($products->sum('mrr')) }}</h2><div class="helper">contas pagantes de todos os produtos</div></div>
        <div class="card"><div class="muted">Perguntas à raposa em {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $month)->format('m/Y') }}</div><h2>{{ $n($aiTotals['messages']) }}</h2><div class="helper">{{ $n($aiTotals['credits'], 1) }} créditos · custo US$ {{ $n($aiTotals['cost'], 2) }}</div></div>
        <div class="card"><div class="muted">Promoções de IA valendo</div><h2>{{ $promotions['running'] }}</h2><div class="helper">{{ $n($promotions['granted']) }} perguntas de brinde para {{ $promotions['clients'] }} cliente(s) neste mês</div></div>
    </div>

    <h3 style="margin-top:24px;">Pede atenção</h3>
    <div class="grid cards">
        <a class="card" href="{{ route('admin.support') }}" style="text-decoration:none;"><div class="muted">Chamados abertos</div><h2>{{ $attention['tickets'] }}</h2></a>
        <div class="card"><div class="muted">Pagantes parados</div><h2>{{ $attention['idlePaying'] }}</h2><div class="helper">pagam e não usam — risco de cancelar</div></div>
        <div class="card"><div class="muted">Testes acabando (3 dias)</div><h2>{{ $attention['trialsEnding'] }}</h2></div>
        @if ($attention['failedCommands'])<div class="card" style="border-color:#fca5a5;"><div class="muted">Ações que não deram certo (7 dias)</div><h2>{{ $attention['failedCommands'] }}</h2></div>@endif
    </div>
    @if ($attention['problems']->isNotEmpty())
        <div class="card" style="margin-top:12px;">
            <strong>⚠️ Contas com problema</strong>
            @foreach ($attention['problems'] as $a)
                <div class="helper"><a href="{{ route('admin.accounts.show', $a) }}">{{ $a->name }}</a> — {{ collect($a->problems)->pluck('label')->implode(' · ') }}</div>
            @endforeach
        </div>
    @endif

    <h3 style="margin-top:24px;">Produtos</h3>
    <div class="grid cards">
        @forelse ($products as $p)
            <div class="card stack">
                <div class="toolbar"><h3 style="margin:0;">{{ $p['integration']->name }}</h3>
                    <span class="muted" style="font-size:12px;">{{ $p['snapshot'] ? 'dados de '.$p['snapshot']->taken_at->timezone('America/Sao_Paulo')->format('d/m H:i') : 'sem retrato ainda' }}</span></div>
                <div>
                    <strong>{{ $p['by_status']['active'] ?? 0 }}</strong> pagantes ·
                    <strong>{{ $p['by_status']['trial'] ?? 0 }}</strong> em teste ·
                    <strong>{{ $p['by_status']['founder'] ?? 0 }}</strong> parceiros ·
                    <strong>{{ ($p['by_status']['canceled'] ?? 0) + ($p['by_status']['expired'] ?? 0) }}</strong> canceladas/vencidas
                </div>
                <div>MRR <strong>{{ $brl($p['mrr']) }}</strong></div>
                <div class="muted">Raposa: {{ $p['ai_clients'] }} cliente(s) · {{ $n($p['ai_messages']) }} perguntas no mês · US$ {{ $n($p['ai_cost'], 2) }}</div>
            </div>
        @empty
            <div class="card empty">Nenhum produto ligado ao Core ainda.</div>
        @endforelse
        <div class="card stack" style="opacity:0.75;">
            <h3 style="margin:0;">Foxy Commerce</h3>
            <div class="muted">Em breve. Entra aqui sozinho quando for ligado ao Core.</div>
        </div>
    </div>

    <h3 style="margin-top:24px;">Quem mais usou a raposa neste mês</h3>
    <div class="card">
        @if ($topAi->isEmpty())
            <div class="empty">Ninguém usou a raposa neste mês ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Cliente</th><th>Perguntas</th><th>Créditos</th><th>Por modo</th><th>Custo (US$)</th></tr></thead>
                <tbody>
                    @foreach ($topAi as $row)
                        <tr>
                            <td>{{ $row['organization']?->name ?? '—' }}</td>
                            <td>{{ $n($row['messages']) }}</td>
                            <td>{{ $n($row['credits'], 1) }}</td>
                            <td class="muted">@foreach ($row['credits_by_tier'] as $tier => $c){{ ['rapida' => 'Rápido', 'equilibrada' => 'Equilibrado', 'avancada' => 'Avançado'][$tier] ?? $tier }} {{ $n($c, 1) }}@if (! $loop->last) · @endif @endforeach</td>
                            <td>{{ $n($row['cost_usd'], 4) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
