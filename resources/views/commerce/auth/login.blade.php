<x-core::plain title="Entrar" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Entrar</h2>
        <p class="muted" style="margin:0">Cada pessoa da equipe entra com o próprio e-mail e senha.</p>
    </div>
    @if (session('status'))<div class="alert success">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('commerce.login') }}" class="stack">
        @csrf
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username">
        </div>
        <div>
            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required autocomplete="current-password">
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="remember" value="1" style="width:auto;"> Manter conectado neste aparelho</label>
        @error('email')<div class="alert error">{{ $message }}</div>@enderror
        <button type="submit">Entrar</button>
    </form>
    <p class="helper" style="margin:0;"><a href="{{ route('commerce.password.request') }}">Esqueci a senha</a> · <a href="{{ route('commerce.signup') }}">Criar conta</a></p>
</x-core::plain>
