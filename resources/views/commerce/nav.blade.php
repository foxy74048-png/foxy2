{{-- Menu do app do lojista. Os módulos entram aqui conforme as fatias de docs/09 (seção 11). --}}
<div class="core-nav-title">{{ $currentStore->name }}</div>
<x-core::nav-link href="{{ route('commerce.dashboard') }}" :active="request()->routeIs('commerce.dashboard')" icon="home">Painel</x-core::nav-link>
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.reports.view'))
    <x-core::nav-link href="{{ route('commerce.reports') }}" :active="request()->routeIs('commerce.reports*')" icon="sparkles">Relatórios</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.orders.manage'))
    <x-core::nav-link href="{{ route('commerce.orders') }}" :active="request()->routeIs('commerce.orders*')" icon="send">Pedidos</x-core::nav-link>
    <x-core::nav-link href="{{ route('commerce.customers') }}" :active="request()->routeIs('commerce.customers*')" icon="users">Clientes</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.finance.manage'))
    <x-core::nav-link href="{{ route('commerce.finance') }}" :active="request()->routeIs('commerce.finance*')" icon="wallet">Financeiro</x-core::nav-link>
    <x-core::nav-link href="{{ route('commerce.whatsapp') }}" :active="request()->routeIs('commerce.whatsapp*')" icon="send">WhatsApp</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.catalog.view'))
    <x-core::nav-link href="{{ route('commerce.products') }}" :active="request()->routeIs('commerce.products*')" icon="copy">Produtos</x-core::nav-link>
    <x-core::nav-link href="{{ route('commerce.categories') }}" :active="request()->routeIs('commerce.categories*')" icon="settings">Categorias</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.channels.manage'))
    <x-core::nav-link href="{{ route('commerce.listings') }}" :active="request()->routeIs('commerce.listings*')" icon="sparkles">Anúncios</x-core::nav-link>
    <x-core::nav-link href="{{ route('commerce.channels') }}" :active="request()->routeIs('commerce.channels*')" icon="home">Marketplaces</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.stock.view'))
    <x-core::nav-link href="{{ route('commerce.stock') }}" :active="request()->routeIs('commerce.stock*') || request()->routeIs('commerce.supply*')" icon="calendar">Estoque</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.store.manage'))
    <x-core::nav-link href="{{ route('commerce.store.settings') }}" :active="request()->routeIs('commerce.store.*')" icon="home">Loja própria</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.team.view'))
    <x-core::nav-link href="{{ route('commerce.team') }}" :active="request()->routeIs('commerce.team*')" icon="users">Equipe</x-core::nav-link>
@endif
@if (\App\Commerce\Access::allows($currentMembership, 'commerce.billing.manage'))
    <x-core::nav-link href="{{ route('commerce.billing') }}" :active="request()->routeIs('commerce.billing*')" icon="wallet">Assinatura</x-core::nav-link>
@endif
<x-core::nav-link href="{{ route('commerce.support') }}" :active="request()->routeIs('commerce.support*')" icon="send">Suporte</x-core::nav-link>
@if ($storeCount > 1)
    <x-core::nav-link href="{{ route('commerce.stores.choose') }}" icon="copy">Trocar de loja</x-core::nav-link>
@endif
<div class="core-nav-title">{{ auth()->user()->name }}</div>
<div class="helper" style="padding:0 12px 8px;">{{ \App\Commerce\Access::label($currentMembership->role) }}</div>
<form method="POST" action="{{ route('commerce.logout') }}" style="margin-top:12px;">
    @csrf
    <button type="submit" class="button secondary" style="width:100%;">Sair</button>
</form>
{{-- Janela da raposa (Foxy AI) em todas as telas do app, quando a raposa do Commerce estiver publicada. --}}
@if (\App\Commerce\Assistant\CommerceFox::isReady())
    @php($foxWidget = rescue(fn () => app(\App\Commerce\Assistant\CommerceFox::class)->widget($currentMembership->organization, auth()->user()), null, false))
    @if ($foxWidget && $foxWidget['assistant'])
        <link rel="stylesheet" href="/core/foxy-assistant.css">
        <script src="/core/foxy-assistant.js" defer></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const reports = @json(\App\Commerce\Access::allows($currentMembership, 'commerce.reports.view'));
            FoxyAssistant.mount({
                endpoint: @json(route('commerce.fox.message')),
                resetEndpoint: @json(route('commerce.fox.reset')),
                csrfToken: document.querySelector('meta[name=csrf-token]').content,
                assistant: @json($foxWidget['assistant']),
                launcher: 'icon',
                getPage: () => document.title,
                suggestions: reports
                    ? ['Quanto vendi este mês e em qual canal?', 'O que está com estoque baixo?', 'Tem anúncio com erro?', 'Escreva um título e uma descrição de anúncio para um produto meu']
                    : ['Quais pedidos estão para separar?', 'Como registro uma venda do WhatsApp?', 'O que está com estoque baixo?'],
                setup: { endpoint: @json(route('commerce.fox.setup')), required: @json($foxWidget['setup']['required']), foxes: @json($foxWidget['setup']['foxes']), visuals: @json($foxWidget['setup']['visuals']) },
            });
        });
        </script>
    @endif
@endif
