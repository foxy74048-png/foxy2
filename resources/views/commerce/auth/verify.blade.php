<x-core::plain title="Confirme o seu e-mail" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Confirme o seu e-mail</h2>
        <p class="muted" style="margin:0">Enviamos um link para <strong>{{ auth()->user()->email }}</strong>. Abra o e-mail e clique em "Confirmar e-mail" para começar.</p>
    </div>
    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('commerce.verification.send') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Enviar o link de novo</button>
    </form>
    <form method="POST" action="{{ route('commerce.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
