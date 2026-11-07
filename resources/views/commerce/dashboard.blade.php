<x-core::layout title="Painel" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @if (session('status'))<div class="alert success" style="margin-bottom:16px;">{{ session('status') }}</div>@endif

    <div class="grid cards">
        <div class="card">
            <div class="muted">Loja</div>
            <h2 style="margin:4px 0;">{{ $currentStore->name }}</h2>
            <div class="helper">Endereço da loja própria: ecommerce.foxy.net.br/{{ $currentStore->slug }}</div>
        </div>
        <div class="card">
            <div class="muted">Teste grátis</div>
            <h2 style="margin:4px 0;">até {{ $trialEndsAt->format('d/m/Y') }}</h2>
        </div>
        <div class="card">
            <div class="muted">Equipe</div>
            <h2 style="margin:4px 0;">{{ $teamCount }} {{ $teamCount === 1 ? 'pessoa' : 'pessoas' }}</h2>
            @if (\App\Commerce\Access::allows($currentMembership, 'commerce.team.view'))<a href="{{ route('commerce.team') }}">Ver equipe</a>@endif
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">O que vem a seguir</h3>
        <p class="muted">O Foxy Commerce está sendo montado por etapas. As próximas a chegar aqui:</p>
        <ol style="margin:0;padding-left:20px;line-height:1.8;">
            <li>Produtos com grade de tamanho e cor, e kits</li>
            <li>Estoque único para todos os canais</li>
            <li>Pedidos do balcão e do WhatsApp</li>
            <li>Loja própria e integração com o Mercado Livre</li>
        </ol>
    </div>
</x-core::layout>
