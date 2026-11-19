<x-core::layout title="Faturas do cartão" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@if ($canManage)@include('finance._add', ['label' => 'Novo cartão', 'href' => route('finance.accounts').'#nova-conta'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))
    @php($monthName = fn ($m) => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m.'-01')->format('m/Y'))

    @include('finance.status')

    @if ($cards->isEmpty())
        <div class="card"><div class="empty">Nenhum cartão cadastrado. Cadastre em <a href="{{ route('finance.accounts') }}#nova-conta">Contas e cartões</a>, com o dia em que a fatura fecha e o dia em que vence.</div></div>
    @endif

    @foreach ($cards as $c)
        @php($card = $c['card'])
        <div class="card" style="margin-bottom:16px;@if ($card->archived_at) opacity:.6; @endif">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline;">
                <h3 style="margin:0;">{{ $card->name }}@if ($card->archived_at) <span class="badge">arquivado</span>@endif</h3>
                <span class="helper">fecha dia {{ $card->closing_day }} · vence dia {{ $card->due_day }}</span>
            </div>

            <div class="grid cards" style="margin-top:12px;">
                <div><div class="muted">Falta pagar no cartão</div><h2 style="margin:4px 0;">{{ $money($c['outstanding']) }}</h2><div class="helper">contando as parcelas futuras</div></div>
                @if ($c['available'] !== null)
                    @php($usedPct = $card->limit_cents > 0 ? min(100, round($c['outstanding'] / $card->limit_cents * 100)) : 0)
                    <div>
                        <div class="muted">Limite disponível</div>
                        <h2 style="margin:4px 0;{{ $c['available'] < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($c['available']) }}</h2>
                        <div style="height:8px;border-radius:4px;background:var(--border);"><div style="height:8px;border-radius:4px;background:{{ $usedPct >= 90 ? 'var(--danger)' : 'var(--primary)' }};width:{{ max(2, $usedPct) }}%;"></div></div>
                        <div class="helper">{{ $usedPct }}% de {{ $money($card->limit_cents) }} em uso</div>
                    </div>
                @endif
                @if ($c['committed'])
                    <div><div class="muted">Já comprometido nos próximos meses</div><h2 style="margin:4px 0;">{{ $money(array_sum($c['committed'])) }}</h2><div class="helper">parcelas em {{ count($c['committed']) }} fatura(s) depois da aberta</div></div>
                @endif
            </div>

            <div class="table-wrap" style="margin-top:12px;"><table>
                <thead><tr><th>Fatura</th><th>Fecha</th><th>Vence</th><th style="text-align:right;">Gastos</th><th style="text-align:right;">Pago</th><th style="text-align:right;">Falta</th><th>Situação</th><th></th></tr></thead>
                <tbody>
                    @foreach ($c['invoices'] as $inv)
                        <tr>
                            <td><strong>{{ $monthName($inv['month']) }}</strong></td>
                            <td>{{ $inv['closing']->format('d/m') }}</td>
                            <td>{{ $inv['due']->format('d/m') }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($inv['charges']) }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($inv['payments']) }}</td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($inv['remaining']) }}</td>
                            <td><span @class(['badge', 'success' => $inv['status'] === 'paga', 'danger' => $inv['status'] === 'atrasada'])>{{ \App\Finance\Accounts\CardInvoices::STATUSES[$inv['status']] }}</span>
                                @if (in_array($inv['status'], ['fechada', 'parcial'], true) && $inv['due']->diffInDays(today(), true) <= 5)<div class="helper" style="color:var(--warning);">vence {{ $inv['due']->isToday() ? 'hoje' : 'em '.(int) today()->diffInDays($inv['due']).' dia(s)' }}</div>@endif
                            </td>
                            <td style="text-align:right;"><a class="button secondary" href="{{ route('finance.cards.show', [$card, 'fatura' => $inv['month']]) }}">Ver{{ $canManage && in_array($inv['status'], ['fechada', 'parcial', 'atrasada'], true) ? ' e pagar' : '' }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endforeach
</x-core::layout>
