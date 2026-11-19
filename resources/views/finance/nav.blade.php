{{-- Menu do app do Finanças. Os módulos entram aqui conforme as fatias de docs/12 (seção 11). --}}
@php($can = fn ($p) => \App\Finance\Access::allows($currentMembership, $p))
<div class="core-nav-title">{{ $currentWallet->name }}</div>
<x-core::nav-link href="{{ route('finance.dashboard') }}" :active="request()->routeIs('finance.dashboard')" icon="home">Início</x-core::nav-link>
@if ($can('finance.view'))
    <x-core::nav-link href="{{ route('finance.transactions') }}" :active="request()->routeIs('finance.transactions*')" icon="send">Lançamentos</x-core::nav-link>
@endif
@if ($can('finance.imports.manage'))
    <x-core::nav-link href="{{ route('finance.statements') }}" :active="request()->routeIs('finance.statements*')" icon="copy">Importar extrato</x-core::nav-link>
@endif
@if ($can('finance.view'))
    <x-core::nav-link href="{{ route('finance.accounts') }}" :active="request()->routeIs('finance.accounts*')" icon="wallet">Contas e cartões</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.categories') }}" :active="request()->routeIs('finance.categories*')" icon="sparkles">Categorias</x-core::nav-link>
@endif
@if ($can('finance.people.view'))
    <x-core::nav-link href="{{ route('finance.people') }}" :active="request()->routeIs('finance.people*')" icon="users">Pessoas na carteira</x-core::nav-link>
@endif
@if ($can('finance.wallet.manage'))
    <x-core::nav-link href="{{ route('finance.wallet.settings') }}" :active="request()->routeIs('finance.wallet.*')" icon="settings">Ajustes da carteira</x-core::nav-link>
@endif

{{-- Trocar de carteira (pessoal ⇄ negócio) sempre à vista, sem tela no meio --}}
<div class="core-nav-title">Carteiras</div>
@foreach ($walletOptions as $o)
    @if ($o['membership']->organization_id === $currentMembership->organization_id)
        <x-core::nav-link href="{{ route('finance.dashboard') }}" :active="true" icon="wallet">{{ $o['wallet']?->name }}</x-core::nav-link>
    @else
        <form method="POST" action="{{ route('finance.wallets.switch') }}" style="margin:0;">
            @csrf
            <input type="hidden" name="organization_id" value="{{ $o['membership']->organization_id }}">
            <button type="submit" class="core-nav-switch">
                <x-core::icon name="wallet" /> <span>{{ $o['wallet']?->name }}</span>
            </button>
        </form>
    @endif
@endforeach
<x-core::nav-link href="{{ route('finance.wallets.new') }}" :active="request()->routeIs('finance.wallets.new')" icon="copy">Nova carteira</x-core::nav-link>

<div class="core-nav-title">{{ auth()->user()->name }}</div>
<div class="helper" style="padding:0 12px 8px;">{{ \App\Finance\Access::label($currentMembership->role) }} · {{ $currentWallet->kindLabel() }}</div>
<x-core::nav-link href="{{ route('finance.profile') }}" :active="request()->routeIs('finance.profile*')" icon="settings">Meu perfil</x-core::nav-link>
<form method="POST" action="{{ route('finance.logout') }}" style="margin-top:12px;">
    @csrf
    <button type="submit" class="button secondary" style="width:100%;">Sair</button>
</form>
{{-- Botões "+ ..." (finance._add) e links com #id: abrem o formulário da página, rolam até ele e focam o 1º campo. --}}
<script>
(function () {
    function openForm(id) {
        var el = document.getElementById(id);
        if (!el) return false;
        if (el.tagName === 'DETAILS') el.open = true;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(function () { var f = el.querySelector('input:not([type=hidden]):not([type=checkbox]),select,textarea'); if (f) f.focus({ preventScroll: true }); }, 400);
        return true;
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest('[data-add-target]');
        if (a && openForm(a.getAttribute('data-add-target'))) { e.preventDefault(); history.replaceState(null, '', '#' + a.getAttribute('data-add-target')); }
    });
    document.addEventListener('DOMContentLoaded', function () { if (location.hash.length > 1) openForm(location.hash.slice(1)); });
})();
</script>
