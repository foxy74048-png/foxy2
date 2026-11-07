<x-core::plain title="Esqueci a senha" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Criar uma nova senha</h2>
        <p class="muted" style="margin:0">Informe o seu e-mail e enviaremos um link.</p>
    </div>
    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('commerce.password.email') }}" class="stack">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        @error('email')<div class="alert error">{{ $message }}</div>@enderror
        <button type="submit">Enviar link</button>
    </form>
    <p class="helper" style="margin:0;"><a href="{{ route('commerce.login') }}">Voltar para entrar</a></p>
</x-core::plain>
