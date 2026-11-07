<x-core::plain title="Loja suspensa" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Loja suspensa</h2>
        <p class="muted" style="margin:0">O acesso a esta loja foi suspenso pela Foxy. Os dados continuam guardados. Fale com o suporte da Foxy para entender e reativar.</p>
    </div>
    <form method="POST" action="{{ route('commerce.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
