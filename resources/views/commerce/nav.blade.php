{{-- Menu do app do lojista. Os módulos entram aqui conforme as fatias de docs/09 (seção 11). --}}
<div class="core-nav-title">{{ $currentStore->name }}</div>
<x-core::nav-link href="{{ route('commerce.dashboard') }}" :active="request()->routeIs('commerce.dashboard')" icon="home">Painel</x-core::nav-link>
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.team.view'))
    <x-core::nav-link href="{{ route('commerce.team') }}" :active="request()->routeIs('commerce.team*')" icon="users">Equipe</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.billing.manage'))
    <x-core::nav-link href="{{ route('commerce.billing') }}" :active="request()->routeIs('commerce.billing*')" icon="wallet">Assinatura</x-core::nav-link>
@endif
@if ($storeCount > 1)
    <x-core::nav-link href="{{ route('commerce.stores.choose') }}" icon="copy">Trocar de loja</x-core::nav-link>
@endif
<div class="core-nav-title">{{ auth()->user()->name }}</div>
<div class="helper" style="padding:0 12px 8px;">{{ \App\Commerce\Access::label($currentMembership->role) }}</div>
<form method="POST" action="{{ route('commerce.logout') }}" style="margin-top:12px;">
    @csrf
    <button type="submit" class="button secondary" style="width:100%;">Sair</button>
</form>
