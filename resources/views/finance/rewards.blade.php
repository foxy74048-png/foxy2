<x-core::layout title="Conquistas" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))

    @include('finance.status')

    {{-- Nível da raposa: pontos de toda a carteira --}}
    <div class="card" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
        <div style="font-size:42px;line-height:1;">🦊</div>
        <div style="flex:1;min-width:240px;">
            <div class="helper" style="margin:0;">Nível da carteira</div>
            <h2 style="margin:2px 0 6px;">{{ $level['name'] }} · {{ number_format($level['points'], 0, ',', '.') }} pts</h2>
            @if ($level['next'])
                <div style="height:10px;background:var(--surface-2, #ece9f7);border-radius:99px;overflow:hidden;"><div style="height:100%;width:{{ round($level['pct'] * 100) }}%;background:var(--primary, #5b3fd1);"></div></div>
                <div class="helper" style="margin:6px 0 0;">Faltam <strong>{{ $level['next_at'] - $level['points'] }} pts</strong> para {{ $level['next'] }}.</div>
            @else
                <div class="helper" style="margin:0;">Nível máximo. 🏆</div>
            @endif
        </div>
        @if ($streak > 0)
            <div class="card" style="margin:0;padding:10px 14px;text-align:center;"><div style="font-size:22px;">🔥 {{ $streak }}</div><div class="helper" style="margin:0;">{{ $streak === 1 ? 'mês' : 'meses' }} seguidos no azul</div></div>
        @endif
    </div>

    {{-- Desafios do mês --}}
    <div class="card" style="margin-top:16px;">
        <h3 style="margin:0;">🎯 Desafios de {{ $monthName }}</h3>
        <p class="helper" style="margin:4px 0 12px;">Até 3 por mês. Vencendo, a carteira ganha {{ \App\Finance\Rewards\Rewards::CHALLENGE_WON }} pts no fim do mês.</p>

        @forelse ($challenges as $item)
            @php($c = $item['challenge'])
            @php($p = $item['progress'])
            <div style="padding:10px 0;border-top:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;">
                    <strong>{{ $c->label }}</strong>
                    @if ($c->status === 'ativo')
                        <form method="POST" action="{{ route('finance.rewards.giveup', $c) }}" onsubmit="return confirm('Desistir deste desafio?');">@csrf<button type="submit" class="secondary">Desistir</button></form>
                    @else
                        <span>{{ $c->status === 'ganhou' ? '🏆 Venceu' : 'Não deu desta vez' }}</span>
                    @endif
                </div>
                @php($bar = min(100, round($p['pct'] * 100)))
                @php($color = $c->kind === 'meta' ? 'var(--primary, #5b3fd1)' : ($p['winning'] ? ($p['pct'] >= 0.8 ? '#b45309' : '#15803d') : '#b91c1c'))
                <div style="height:8px;background:var(--surface-2, #ece9f7);border-radius:99px;overflow:hidden;margin-top:6px;"><div style="height:100%;width:{{ $bar }}%;background:{{ $color }};"></div></div>
                <div class="helper" style="margin:4px 0 0;">{{ $p['text'] }}</div>
            </div>
        @empty
            <p class="helper" style="margin:0 0 8px;">Nenhum desafio aceito este mês.</p>
        @endforelse

        @if ($suggestions->isNotEmpty() && $canAccept)
            <h4 style="margin:16px 0 6px;">Sugeridos pela raposa, com os seus números</h4>
            @foreach ($suggestions as $i => $s)
                <form method="POST" action="{{ route('finance.rewards.accept') }}" style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;padding:8px 0;border-top:1px dashed var(--border);">
                    @csrf
                    <input type="hidden" name="suggestion" value="{{ $i }}">
                    <div><strong>{{ $s['label'] }}</strong><div class="helper" style="margin:0;">{{ $s['why'] }}</div></div>
                    <button type="submit">Aceitar desafio</button>
                </form>
            @endforeach
        @elseif ($suggestions->isEmpty() && $challenges->isEmpty())
            <p class="helper" style="margin:0;">Ainda não há números suficientes para sugerir desafios. Crie uma meta ou lance os gastos do mês e volte aqui.</p>
        @endif
    </div>

    {{-- Quem mais ajudou (carteira com mais de uma pessoa) --}}
    @if ($ranking->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin:0;">👥 Quem mais ajudou em {{ $monthName }}</h3>
            <p class="helper" style="margin:4px 0 10px;">Os pontos são da carteira, de todo mundo junto. Aqui aparece a ajuda de cada um.</p>
            @foreach ($ranking as $i => $r)
                <div style="display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-top:1px solid var(--border);">
                    <span>{{ [0 => '🥇', 1 => '🥈', 2 => '🥉'][$i] ?? '·' }} <strong>{{ $r['name'] }}</strong></span>
                    <span class="helper" style="margin:0;">guardou {{ $money($r['saved']) }} · {{ $r['entries'] }} lançamento(s)</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Conquistas --}}
    <div class="card" style="margin-top:16px;">
        <h3 style="margin:0 0 10px;">🏅 Conquistas</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;">
            @foreach ($badges as $b)
                <div class="card" style="margin:0;padding:12px;{{ $b['earned_at'] ? '' : 'opacity:.55;filter:grayscale(1);' }}">
                    <strong>{{ $b['name'] }}</strong>
                    <div class="helper" style="margin:4px 0 0;">{{ $b['how'] }}</div>
                    <div class="helper" style="margin:4px 0 0;">{{ $b['earned_at'] ? '✅ Ganha em '.$b['earned_at']->format('d/m/Y') : '🔒 Falta' }} · {{ $b['points'] }} pts</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="grid cards" style="margin-top:16px;">
        <div class="card">
            <h3 style="margin:0 0 8px;">Como a carteira ganha pontos</h3>
            <ul style="margin:0;padding-left:18px;line-height:1.8;">
                <li>Mês fechado no azul (sobrou dinheiro): <strong>{{ \App\Finance\Rewards\Rewards::MONTH_POSITIVE }} pts</strong></li>
                <li>Meta com prazo no ritmo: <strong>{{ \App\Finance\Rewards\Rewards::GOAL_ON_TRACK }} pts</strong> por meta</li>
                <li>Orçamento respeitado: <strong>{{ \App\Finance\Rewards\Rewards::BUDGET_KEPT }} pts</strong> por categoria</li>
                <li>Fatura do cartão paga até o vencimento: <strong>{{ \App\Finance\Rewards\Rewards::INVOICE_ON_TIME }} pts</strong></li>
                <li>Extrato do mês todo revisado: <strong>{{ \App\Finance\Rewards\Rewards::STATEMENT_REVIEWED }} pts</strong></li>
                <li>Desafio vencido: <strong>{{ \App\Finance\Rewards\Rewards::CHALLENGE_WON }} pts</strong></li>
            </ul>
            <p class="helper" style="margin:8px 0 0;">Os pontos do mês entram quando ele fecha. Abrir o app não vale ponto: vale cuidar do dinheiro.</p>
        </div>
        <div class="card">
            <h3 style="margin:0 0 8px;">Últimos pontos</h3>
            @forelse ($history as $r)
                <div style="display:flex;justify-content:space-between;gap:8px;padding:4px 0;border-top:1px solid var(--border);"><span>{{ $r->label }}</span><strong>+{{ $r->points }}</strong></div>
            @empty
                <p class="helper" style="margin:0;">Os primeiros pontos chegam quando o mês fechar, ou ao ganhar uma conquista.</p>
            @endforelse
        </div>
    </div>
</x-core::layout>
