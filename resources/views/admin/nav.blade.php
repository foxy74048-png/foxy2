{{-- Menu do Foxy Admin --}}
<div class="core-nav-title">Foxy Admin</div>
<x-core::nav-link href="{{ route('admin.dashboard') }}" :active="request()->routeIs('admin.dashboard')" icon="home">Painel</x-core::nav-link>
<x-core::nav-link href="{{ route('admin.accounts') }}" :active="request()->routeIs('admin.accounts')" icon="users">Contas</x-core::nav-link>
<x-core::nav-link href="{{ route('admin.promotions') }}" :active="request()->routeIs('admin.promotions*')" icon="sparkles">Promoções de IA</x-core::nav-link>
<x-core::nav-link href="{{ route('admin.announcements') }}" :active="request()->routeIs('admin.announcements*')" icon="send">Comunicados</x-core::nav-link>
<x-core::nav-link href="{{ route('admin.insights') }}" :active="request()->routeIs('admin.insights*')" icon="copy">Ideias e dúvidas</x-core::nav-link>
<div class="core-nav-title">Produtos</div>
<x-core::nav-link href="https://studio.foxy.net.br/admin" icon="calendar">Admin do Foxy Studio</x-core::nav-link>
<x-core::nav-link href="/console" icon="settings">Console da Foxy AI</x-core::nav-link>
<form method="POST" action="{{ route('logout') }}" style="margin-top:12px;">
    @csrf
    <button type="submit" class="button secondary" style="width:100%;">Sair</button>
</form>
