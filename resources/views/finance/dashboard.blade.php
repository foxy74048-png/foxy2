<x-core::layout title="Início" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>
        @if ($canManagePeople)@include('finance._add', ['label' => 'Convidar pessoa', 'href' => route('finance.people').'#convidar'])@endif
    </x-slot:actions>

    @include('finance.status')

    <div class="grid cards">
        <div class="card">
            <div class="muted">Carteira</div>
            <h2 style="margin:4px 0;font-size:1.1rem;">{{ $currentWallet->name }}</h2>
            <div class="helper">{{ $currentWallet->kindLabel() }}@if ($currentWallet->is_mei) · MEI @endif</div>
        </div>
        <a class="card" href="{{ route('finance.people') }}">
            <div class="muted">Pessoas na carteira</div>
            <h2 style="margin:4px 0;">{{ $people }}</h2>
            <div class="helper">{{ $currentWallet->isBusiness() ? 'convide o sócio para dividir pró-labore e lucro' : 'convide quem divide as contas com você' }}</div>
        </a>
        <div class="card">
            <div class="muted">Plano</div>
            <h2 style="margin:4px 0;font-size:1.1rem;">Grátis</h2>
            <div class="helper">em fase de testes</div>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">O que vem por aí</h3>
        <p class="muted" style="margin-top:0;">O Foxy Finanças está sendo montado em partes. Nesta carteira, em breve:</p>
        <ul style="margin:0;padding-left:20px;line-height:1.8;">
            <li><strong>Contas e cartões</strong> e lançar um gasto em 3 toques, com parcelado e repetição todo mês.</li>
            <li><strong>Importar o extrato</strong> do banco e a fatura do cartão (OFX, planilha ou PDF), sem digitar nada.</li>
            <li><strong>Onde você gasta mais:</strong> categorias, lugares, mês a mês e o que mudou.</li>
            @if ($currentWallet->isBusiness())
                <li><strong>Sócios:</strong> pró-labore, retiradas, o lucro do mês e quanto cabe a cada um.</li>
            @endif
            <li><strong>A raposa</strong> respondendo sobre o seu dinheiro, e lançar pelo WhatsApp.</li>
        </ul>
    </div>
</x-core::layout>
