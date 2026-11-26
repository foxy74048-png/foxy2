<x-core::layout title="Métricas" product="Foxy Admin" subtitle="Administrativo da Foxy">
    <x-slot:nav>@include('admin.nav')</x-slot:nav>
    @php
        $n = fn ($v, $d = 0) => $v === null ? '—' : number_format((float) $v, $d, ',', '.');
        $pct = fn ($v) => $v === null ? '—' : number_format((float) $v, 1, ',', '.').'%';
        $brl = fn ($v) => $v === null ? '—' : 'R$ '.number_format((float) $v, 2, ',', '.');
        $exportQuery = array_filter(['produto' => $filters['produto'] ?? null, 'meses' => $filters['meses']]);
    @endphp

    <form class="card form-grid" method="GET" action="{{ route('admin.metrics') }}" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div>
            <label for="produto">Produto</label>
            <select id="produto" name="produto">
                <option value="">Todos</option>
                @foreach ($integrations as $i)<option value="{{ $i->id }}" @selected(($filters['produto'] ?? '') === $i->id)>{{ $i->name }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="meses">Período</label>
            <select id="meses" name="meses">
                @foreach (\App\Admin\Metrics\ProductMetrics::MONTHS as $m)<option value="{{ $m }}" @selected($filters['meses'] === $m)>Últimos {{ $m }} meses</option>@endforeach
            </select>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="submit">Filtrar</button>
            <a class="button secondary" href="{{ route('admin.metrics', $exportQuery + ['exportar' => 'planilha']) }}" title="Baixar planilha (abre no Excel)">Planilha</a>
            <a class="button secondary" href="{{ route('admin.metrics', $exportQuery + ['exportar' => 'pdf']) }}" title="Baixar PDF">PDF</a>
        </div>
    </form>

    <div class="grid cards" style="margin-top:16px;">
        <div class="card"><div class="muted">Faturamento mensal (MRR)</div><h2>{{ $brl($summary['mrr']) }}</h2><div class="helper">{{ $summary['paying'] }} pagante(s) · {{ $summary['trial'] }} em teste</div></div>
        <div class="card"><div class="muted">Conversão de teste em pagante</div><h2>{{ $pct($summary['conversion']) }}</h2><div class="helper">cadastros do período que já decidiram (fora quem ainda está no teste)</div></div>
        <div class="card"><div class="muted">Churn deste mês</div><h2>{{ $pct($summary['churn']) }}</h2><div class="helper">{{ $summary['lost_this_month'] }} pagante(s) saíram · sobre os pagantes do início do mês</div></div>
        <div class="card"><div class="muted">Ativação em 7 dias</div><h2>{{ $pct($summary['activation']) }}</h2><div class="helper">de {{ $summary['signups'] }} cadastro(s) no período · mediana de {{ $n($summary['median_days_to_activate'], 1) }} dia(s) até o 1º uso</div></div>
    </div>

    <div class="helper" style="margin-top:12px;">
        @if ($since)
            Histórico de mudanças desde {{ $since->timezone('America/Sao_Paulo')->format('d/m/Y') }}. Antes disso só se sabe o cadastro e a ativação; churn e pagantes do mês aparecem a partir daí.
        @else
            O histórico de mudanças ainda não começou: aparece quando os produtos enviarem as contas.
        @endif
        Ativação = 1º uso de verdade (Studio: 1º agendamento; Commerce: 1º pedido; Finanças: 1º lançamento). Pagante = MRR maior que zero.
    </div>

    <h3 style="margin-top:24px;">Mês a mês</h3>
    <div class="card">
        <div class="table-wrap"><table>
            <thead><tr><th>Mês</th><th>Cadastros</th><th>Novos pagantes</th><th>Saíram (pagantes)</th><th>MRR perdido</th><th>Pagantes no fim</th><th>MRR no fim</th><th>Churn</th></tr></thead>
            <tbody>
                @foreach (array_reverse($monthly) as $m)
                    <tr>
                        <td><strong>{{ $m['label'] }}</strong>@if ($m['month']->isSameMonth(now()))<div class="helper">até agora</div>@endif</td>
                        <td>{{ $m['signups'] }}</td>
                        <td>{{ $m['new_paying'] }}</td>
                        <td>{{ $m['lost'] }}</td>
                        <td>{{ $m['mrr_lost'] > 0 ? $brl($m['mrr_lost']) : '—' }}</td>
                        <td>{{ $n($m['paying_end']) }}</td>
                        <td>{{ $brl($m['mrr_end']) }}</td>
                        <td>{{ $pct($m['churn']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>

    <h3 style="margin-top:24px;">Turmas por mês de cadastro</h3>
    <div class="card">
        <div class="helper" style="margin-bottom:8px;">Quantas contas de cada mês de cadastro ativaram, viraram pagantes e continuam em uso (ativa, grátis, em teste ou Parceiro Fundador) depois de 1, 2 e 3 meses.</div>
        <div class="table-wrap"><table>
            <thead><tr><th>Cadastro</th><th>Contas</th><th>Ativaram em 7 dias</th><th>Dias até o 1º uso</th><th>Viraram pagantes</th><th>Em uso após 1 mês</th><th>Após 2 meses</th><th>Após 3 meses</th></tr></thead>
            <tbody>
                @foreach ($cohorts as $c)
                    <tr>
                        <td><strong>{{ $c['label'] }}</strong></td>
                        <td>{{ $c['signups'] }}</td>
                        <td>@if ($c['signups']){{ $c['activated_7d'] }} <span class="muted">({{ $pct(round($c['activated_7d'] / $c['signups'] * 100, 1)) }})</span>@else — @endif</td>
                        <td>{{ $n($c['median_days'], 1) }}</td>
                        <td>@if ($c['decided']){{ $c['paid'] }} <span class="muted">({{ $pct($c['conversion']) }})</span>@elseif ($c['signups']) <span class="muted">ainda no teste</span>@else — @endif</td>
                        @foreach (\App\Admin\Metrics\ProductMetrics::RETENTION_MONTHS as $r)
                            @php($cell = $c['retention'][$r])
                            <td>
                                @if ($cell === null)
                                    <span class="muted">{{ $c['signups'] ? 'ainda não' : '—' }}</span>
                                @else
                                    {{ $pct($cell['pct']) }}@if ($cell['known'])<span class="muted"> ({{ $cell['live'] }}/{{ $cell['known'] }})</span>@endif
                                    @if ($cell['unknown'])<div class="helper">{{ $cell['unknown'] }} sem histórico</div>@endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table></div>
    </div>
</x-core::layout>
