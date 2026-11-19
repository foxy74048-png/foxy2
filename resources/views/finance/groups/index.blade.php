<x-core::layout title="Despesas em grupo" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@if ($canManage)@include('finance._add', ['label' => 'Novo grupo', 'target' => 'novo-grupo'])@endif</x-slot:actions>
    @php($money = fn ($c) => \App\Platform\Support\Money::format($c))

    @include('finance.status')

    @if ($canManage)
        <form class="card form-grid" id="novo-grupo" method="POST" action="{{ route('finance.groups.store') }}" style="align-items:end;">
            @csrf
            <div style="grid-column:1/-1;"><h3 style="margin:0;">Novo grupo</h3><p class="helper" style="margin:4px 0 0;">Viagem, república, festa, presente coletivo: lance cada despesa com quem pagou e o sistema mostra quanto cada um gastou, pagou e quem paga quem no fim. Você já entra no grupo.</p></div>
            <div><label for="g-name">Nome</label><input id="g-name" name="name" required maxlength="80" placeholder="Ex.: Viagem Floripa"></div>
            <div style="grid-column:span 2;"><label for="g-names">Quem vai (separe por vírgula)</label><input id="g-names" name="names" maxlength="1000" placeholder="Ex.: Bia, Caio, Duda"></div>
            @if ($contacts->isNotEmpty())
                <div style="grid-column:1/-1;">
                    <span class="helper">ou escolha de "Quem me deve":</span>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:4px;">
                        @foreach ($contacts as $c)<label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="contact_ids[]" value="{{ $c->id }}" style="width:auto;"> {{ $c->name }}</label>@endforeach
                    </div>
                </div>
            @endif
            <div><button type="submit">Criar grupo</button></div>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Grupos</h3>
        @forelse ($groups as $row)
            @php($g = $row['group'])
            @php($me = $row['me'])
            <a href="{{ route('finance.groups.show', $g) }}" style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;@if ($g->isClosed()) opacity:.6; @endif">
                <span><strong>{{ $g->name }}</strong>@if ($g->isClosed()) <span class="badge">encerrado</span>@endif<div class="helper">{{ $row['people'] }} pessoas · total {{ $money($row['total']) }}</div></span>
                <span style="text-align:right;">
                    @if ($me && $me['net'] > 0)<strong style="color:var(--success-text);">você recebe {{ $money($me['net']) }}</strong>
                    @elseif ($me && $me['net'] < 0)<strong style="color:var(--danger-text);">você deve {{ $money(-$me['net']) }}</strong>
                    @else <span class="muted">você está quite</span>
                    @endif
                    @if ($me)<div class="helper">sua parte: {{ $money($me['share']) }}</div>@endif
                </span>
            </a>
        @empty
            <div class="empty">Nenhum grupo ainda.</div>
        @endforelse
    </div>
</x-core::layout>
