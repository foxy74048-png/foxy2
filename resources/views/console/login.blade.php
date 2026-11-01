<x-core::plain title="Entrar" product="Foxy AI">
    <div>
        <h2 style="margin:0 0 4px">Console de testes</h2>
        <p class="muted" style="margin:0">Acesso da equipe Foxy.</p>
    </div>
    <form method="POST" action="/console/login" class="stack">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        @error('email')<div class="alert error">{{ $message }}</div>@enderror
        <button type="submit">Entrar</button>
    </form>
</x-core::plain>
