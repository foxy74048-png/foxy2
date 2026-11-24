{{-- Menu do app do Finanças. Os módulos entram aqui conforme as fatias de docs/12 (seção 11). --}}
@php($can = fn ($p) => \App\Finance\Access::allows($currentMembership, $p))
<div class="core-nav-title">{{ $currentWallet->name }}</div>
<x-core::nav-link href="{{ route('finance.dashboard') }}" :active="request()->routeIs('finance.dashboard')" icon="home">Início</x-core::nav-link>
@if ($can('finance.reports.view'))
    <x-core::nav-link href="{{ route('finance.insights') }}" :active="request()->routeIs('finance.insights')" icon="sparkles">Onde vai o dinheiro</x-core::nav-link>
@endif
@if ($can('finance.view'))
    <x-core::nav-link href="{{ route('finance.budgets') }}" :active="request()->routeIs('finance.budgets*')" icon="wallet">Orçamento</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.goals') }}" :active="request()->routeIs('finance.goals*')" icon="sparkles">Metas de guardar</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.rewards') }}" :active="request()->routeIs('finance.rewards*')" icon="sparkles">Conquistas</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.debts') }}" :active="request()->routeIs('finance.debts*')" icon="users">Quem me deve</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.groups') }}" :active="request()->routeIs('finance.groups*')" icon="users">Despesas em grupo</x-core::nav-link>
    @if ($currentWallet->isBusiness())
        <x-core::nav-link href="{{ route('finance.partners') }}" :active="request()->routeIs('finance.partners*')" icon="users">Sócios{{ $currentWallet->is_mei ? ' e MEI' : '' }}</x-core::nav-link>
    @endif
@endif
@if ($can('finance.view'))
    <x-core::nav-link href="{{ route('finance.transactions') }}" :active="request()->routeIs('finance.transactions*')" icon="send">Lançamentos</x-core::nav-link>
@endif
@if ($can('finance.imports.manage'))
    <x-core::nav-link href="{{ route('finance.statements') }}" :active="request()->routeIs('finance.statements*')" icon="copy">Importar extrato</x-core::nav-link>
@endif
@if ($can('finance.view'))
    <x-core::nav-link href="{{ route('finance.accounts') }}" :active="request()->routeIs('finance.accounts*')" icon="wallet">Contas e cartões</x-core::nav-link>
    <x-core::nav-link href="{{ route('finance.cards') }}" :active="request()->routeIs('finance.cards*')" icon="calendar">Faturas do cartão</x-core::nav-link>
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
{{-- Janela da raposa (Foxy AI) em todas as telas, quando a raposa do Finanças estiver publicada (fatia 8). --}}
@if (\App\Finance\Assistant\FinanceFox::isReady())
    @php($foxWidget = rescue(fn () => app(\App\Finance\Assistant\FinanceFox::class)->widget($currentMembership->organization, auth()->user()), null, false))
    @if ($foxWidget && $foxWidget['assistant'])
        @php($foxSuggestions = $currentWallet->isBusiness() ? ['Qual foi o lucro deste mês e quanto cabe a cada sócio?', 'Onde o negócio mais gastou este mês?', 'Quanto já faturei no ano como MEI?', 'Quem está me devendo?'] : ['Quanto gastei com delivery este ano?', 'Onde dá para economizar?', 'Quanto falta para a minha meta?', 'Quem está me devendo?'])
        <link rel="stylesheet" href="/core/foxy-assistant.css">
        <script src="/core/foxy-assistant.js" defer></script>
        <script>
        document.addEventListener('DOMContentLoaded', () => {
            FoxyAssistant.mount({
                endpoint: @json(route('finance.fox.message')),
                resetEndpoint: @json(route('finance.fox.reset')),
                csrfToken: document.querySelector('meta[name=csrf-token]').content,
                assistant: @json($foxWidget['assistant']),
                launcher: 'icon',
                getPage: () => document.title,
                suggestions: @json($foxSuggestions),
                setup: { endpoint: @json(route('finance.fox.setup')), required: @json($foxWidget['setup']['required']), foxes: @json($foxWidget['setup']['foxes']), visuals: @json($foxWidget['setup']['visuals']) },
            });
        });
        </script>
    @endif
@endif
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
