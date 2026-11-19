<x-core::layout title="Início" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>
        @if ($canManage)@include('finance._add', ['label' => 'Lançamento', 'href' => route('finance.transactions').'#lancar'])@endif
    </x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))

    @include('finance.status')

    @if (! $hasAccounts && $canManage)
        <div class="alert info" style="margin-bottom:16px;">
            Comece cadastrando onde o seu dinheiro fica (conta do banco, carteira, cartão) e lance o primeiro gasto: <a href="{{ route('finance.transactions') }}#lancar">começar</a>.
        </div>
    @endif

    @foreach ($invoicesToPay as $inv)
        <div @class(['alert', 'error' => $inv['status'] === 'atrasada', 'info' => $inv['status'] !== 'atrasada']) style="margin-bottom:12px;">
            Fatura do <strong>{{ $inv['card']->name }}</strong> de {{ \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $inv['month'].'-01')->format('m/Y') }}:
            <strong>{{ $money($inv['remaining']) }}</strong> {{ $inv['status'] === 'atrasada' ? 'venceu em '.$inv['due']->format('d/m') : ($inv['due']->isToday() ? 'vence hoje' : 'vence em '.$inv['due']->format('d/m')) }}.
            <a href="{{ route('finance.cards.show', [$inv['card'], 'fatura' => $inv['month']]) }}">Ver e registrar o pagamento</a>
        </div>
    @endforeach

    <h2 style="margin:0 0 12px;font-size:1.1rem;">{{ \Illuminate\Support\Str::ucfirst(\App\Finance\Transactions\TransactionFilters::monthName(today())) }}</h2>
    <div class="grid cards">
        <a class="card" href="{{ route('finance.transactions', ['tipo' => 'recebimento']) }}"><div class="muted">Entrou</div><h2 style="margin:4px 0;color:var(--success-text);">{{ $money($income) }}</h2></a>
        <a class="card" href="{{ route('finance.transactions', ['tipo' => 'gasto']) }}"><div class="muted">Saiu</div><h2 style="margin:4px 0;">{{ $money($expense) }}</h2></a>
        <div class="card"><div class="muted">Sobrou</div><h2 style="margin:4px 0;{{ $income - $expense < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($income - $expense) }}</h2>@if ($savingsRate !== null)<div class="helper">{{ number_format($savingsRate * 100, 0, ',', '.') }}% do que entrou</div>@endif</div>
        <a class="card" href="{{ route('finance.accounts') }}"><div class="muted">Saldo nas contas hoje</div><h2 style="margin:4px 0;{{ $balance < 0 ? 'color:var(--danger-text);' : '' }}">{{ $money($balance) }}</h2><div class="helper">sem os cartões</div></a>
        @if ($pace && $pace['spent'] > 0)
            <a class="card" href="{{ $canSeeInsights ? route('finance.insights') : '#' }}"><div class="muted">No ritmo atual, o mês fecha em</div><h2 style="margin:4px 0;">{{ $money($pace['projected']) }}</h2><div class="helper">{{ $money($pace['per_day']) }} por dia</div></a>
        @endif
    </div>

    {{-- Orçamento: o que passou ou está perto do limite --}}
    @if ($goals->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <div style="display:flex;justify-content:space-between;"><h3 style="margin:0;">Metas</h3><a class="helper" href="{{ route('finance.goals') }}">ver todas</a></div>
            <div class="stack" style="margin-top:8px;">
                @foreach ($goals as $g)
                    @php($p = $g->progress())
                    <div>
                        <div style="display:flex;justify-content:space-between;gap:8px;"><span>{{ $g->name }}</span><span><strong>{{ $money($p['saved']) }}</strong> <span class="muted">de {{ $money($g->target_cents) }}</span></span></div>
                        <div style="height:8px;border-radius:4px;background:var(--border);margin-top:4px;"><div style="height:8px;border-radius:4px;background:var(--primary);width:{{ max(2, round($p['pct'] * 100)) }}%;"></div></div>
                        @if ($p['per_month'])<div class="helper">guardar {{ $money($p['per_month']) }} por mês até {{ $g->deadline->format('m/Y') }}{{ $p['on_track'] === false ? ' · atrasada' : '' }}</div>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($budgetAlerts->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <div class="section-title" style="display:flex;justify-content:space-between;"><h3 style="margin:0;">Orçamento pedindo atenção</h3><a class="helper" href="{{ route('finance.budgets') }}">ver todos</a></div>
            <div class="stack" style="margin-top:8px;">@foreach ($budgetAlerts as $b)@include('finance._budget-bar', ['b' => $b])@endforeach</div>
        </div>
    @endif
    @if ($canSeeInsights)
        <p style="margin:12px 0 0;"><a href="{{ route('finance.insights') }}">Ver onde dá para economizar →</a> <span class="helper">gastinhos, assinaturas, o que mais subiu, reserva de emergência e "se cortar pela metade".</span></p>
    @endif

    <div class="grid two" style="align-items:start;margin-top:16px;">
        <div class="card">
            <h3 style="margin-top:0;">Onde você mais gastou neste mês</h3>
            @if (! $top)
                <div class="empty">Nenhum gasto lançado neste mês ainda.</div>
            @else
                @php($max = max(array_column($top, 'total')))
                <div class="stack">
                    @foreach ($top as $row)
                        <a href="{{ route('finance.transactions', array_filter(['categoria' => $row['id'], 'tipo' => 'gasto'])) }}" style="text-decoration:none;color:inherit;">
                            <div style="display:flex;justify-content:space-between;gap:8px;"><span>{{ $row['name'] }}</span><strong>{{ $money($row['total']) }}</strong></div>
                            <div style="height:8px;border-radius:4px;background:var(--border);margin-top:4px;"><div style="height:8px;border-radius:4px;background:var(--primary);width:{{ max(2, round($row['total'] / $max * 100)) }}%;"></div></div>
                        </a>
                    @endforeach
                </div>
                <p class="helper" style="margin-bottom:0;">{{ $expense > 0 ? round($top[0]['total'] / $expense * 100) : 0 }}% do que saiu foi em {{ $top[0]['name'] }}.</p>
            @endif
        </div>
        <div class="card">
            <h3 style="margin-top:0;">Para os próximos 7 dias</h3>
            @if ($upcoming->isEmpty())
                <div class="empty">Nada previsto. Contas que se repetem (aluguel, salário) aparecem aqui quando lançadas como "todo mês".</div>
            @else
                <div class="table-wrap"><table>
                    <tbody>
                        @foreach ($upcoming as $t)
                            <tr>
                                <td style="white-space:nowrap;">{{ $t->date->format('d/m') }}@if ($t->date->lt(today()))<div class="helper" style="color:var(--danger-text);">atrasado</div>@endif</td>
                                <td>{{ $t->description }}<div class="helper">{{ $t->account?->name }}</div></td>
                                <td style="text-align:right;white-space:nowrap;{{ $t->kind === 'recebimento' ? 'color:var(--success-text);' : '' }}">{{ $t->kind === 'gasto' ? '− ' : '+ ' }}{{ $money($t->amount_cents) }}</td>
                                @if ($canManage)<td style="text-align:right;"><form method="POST" action="{{ route('finance.transactions.confirm', $t) }}">@csrf<button type="submit" class="secondary">{{ $t->kind === 'recebimento' ? 'Recebi' : 'Paguei' }}</button></form></td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>
    </div>

    <div class="grid cards" style="margin-top:16px;">
        <a class="card" href="{{ route('finance.people') }}">
            <div class="muted">Pessoas na carteira</div>
            <h2 style="margin:4px 0;">{{ $people }}</h2>
            <div class="helper">{{ $currentWallet->isBusiness() ? 'convide o sócio para dividir pró-labore e lucro' : 'convide quem divide as contas com você' }}</div>
        </a>
        <div class="card">
            <div class="muted">Em breve</div>
            <div class="helper" style="margin-top:4px;line-height:1.7;">"Quem me deve"{{ $currentWallet->isBusiness() ? ', sócios e pró-labore' : '' }}, a raposa e lançar pelo WhatsApp.</div>
        </div>
    </div>
</x-core::layout>
