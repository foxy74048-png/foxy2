<x-core::layout title="Calendário" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('platform.export-buttons')</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))

    @include('finance.status')

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
        <a class="button secondary" href="{{ route('finance.calendar', ['mes' => $prev]) }}" aria-label="Mês anterior">‹</a>
        <h2 style="margin:0;font-size:1.2rem;">{{ \Illuminate\Support\Str::ucfirst($monthName) }}</h2>
        <a class="button secondary" href="{{ route('finance.calendar', ['mes' => $next]) }}" aria-label="Mês seguinte">›</a>
        @if ($month->format('Y-m') !== today()->format('Y-m'))<a class="helper" href="{{ route('finance.calendar') }}">voltar para este mês</a>@endif
    </div>

    <div class="grid cards">
        <div class="card"><div class="muted">Falta pagar</div><h2 style="margin:4px 0;font-size:1.1rem;color:#b91c1c;">{{ $money($totals['to_pay']) }}</h2><div class="helper">contas previstas e faturas</div></div>
        <div class="card"><div class="muted">Falta receber</div><h2 style="margin:4px 0;font-size:1.1rem;color:#15803d;">{{ $money($totals['to_receive']) }}</h2><div class="helper">recebimentos previstos</div></div>
        <div class="card"><div class="muted">Já saiu</div><h2 style="margin:4px 0;font-size:1.1rem;">{{ $money($totals['paid']) }}</h2></div>
        <div class="card"><div class="muted">Já entrou</div><h2 style="margin:4px 0;font-size:1.1rem;">{{ $money($totals['received']) }}</h2></div>
    </div>

    {{-- Grade do mês: em cada dia, o que sai (vermelho) e o que entra (verde); previsto com borda tracejada. --}}
    <div class="card" style="margin-top:16px;overflow-x:auto;">
        <table style="table-layout:fixed;min-width:640px;">
            <thead><tr>@foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $d)<th style="text-align:center;">{{ $d }}</th>@endforeach</tr></thead>
            <tbody>
                @foreach ($weeks as $week)
                    <tr>
                        @foreach ($week as $day)
                            @php($list = $day ? ($byDay[$day->toDateString()] ?? collect()) : collect())
                            <td style="vertical-align:top;height:84px;padding:4px;{{ $day && $day->isToday() ? 'background:var(--surface-2, #ece9f7);' : '' }}">
                                @if ($day)
                                    <a href="#dia-{{ $day->format('d') }}" style="font-weight:600;text-decoration:none;">{{ $day->day }}</a>
                                    @foreach ($list->take(3) as $i)
                                        <div title="{{ $i['label'] }} · {{ $money($i['amount']) }} · {{ $i['status'] }}"
                                             style="font-size:11px;line-height:1.3;margin-top:2px;padding:1px 4px;border-radius:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
                                                    color:{{ $i['sign'] < 0 ? '#b91c1c' : ($i['sign'] > 0 ? '#15803d' : 'inherit') }};
                                                    border:1px {{ $i['done'] ? 'solid transparent' : 'dashed currentColor' }};{{ $i['done'] ? 'opacity:.7;' : '' }}">
                                            {{ $i['kind'] === 'invoice' ? '💳 ' : '' }}{{ $money($i['amount']) }} {{ $i['label'] }}
                                        </div>
                                    @endforeach
                                    @if ($list->count() > 3)<div class="helper" style="margin:2px 0 0;font-size:11px;">+{{ $list->count() - 3 }}</div>@endif
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="helper" style="margin:8px 0 0;">Borda tracejada = ainda previsto. As compras no cartão entram na fatura, no dia em que ela vence.</p>
    </div>

    {{-- Lista dia a dia (a mesma coisa, mais fácil de ler no celular) --}}
    <div class="card" style="margin-top:16px;">
        <h3 style="margin:0 0 8px;">Dia a dia</h3>
        @forelse ($byDay as $date => $list)
            @php($d = \Illuminate\Support\Carbon::parse($date))
            <div id="dia-{{ $d->format('d') }}" style="padding:8px 0;border-top:1px solid var(--border);">
                <strong>{{ $d->format('d/m') }} · {{ ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][$d->dayOfWeek] }}</strong>
                @foreach ($list as $i)
                    <div style="display:flex;justify-content:space-between;gap:10px;padding:3px 0;">
                        <span>{{ $i['kind'] === 'invoice' ? '💳 ' : '' }}{{ $i['label'] }} <span class="helper" style="margin:0;">· {{ $i['account'] }} · {{ $i['status'] }}</span></span>
                        <strong style="white-space:nowrap;color:{{ $i['sign'] < 0 ? '#b91c1c' : ($i['sign'] > 0 ? '#15803d' : 'inherit') }};">{{ $i['sign'] < 0 ? '−' : ($i['sign'] > 0 ? '+' : '') }}{{ $money($i['amount']) }}</strong>
                    </div>
                @endforeach
            </div>
        @empty
            <p class="helper" style="margin:0;">Nada lançado nem previsto neste mês.</p>
        @endforelse
    </div>
</x-core::layout>
