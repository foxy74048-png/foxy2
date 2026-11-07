<x-core::plain title="Nova senha" product="Foxy Commerce">
    <div>
        <h2 style="margin:0 0 4px">Nova senha</h2>
    </div>
    <form method="POST" action="{{ route('commerce.password.update') }}" class="stack">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div>
            <label for="email">E-mail</label>
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username">
        </div>
        <div>
            <label for="password">Nova senha (mínimo 8 caracteres)</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password">
        </div>
        <div>
            <label for="password_confirmation">Repita a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>
        @if ($errors->any())<div class="alert error">{{ $errors->first() }}</div>@endif
        <button type="submit">Salvar senha</button>
    </form>
</x-core::plain>
