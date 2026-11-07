<x-core::plain title="Criar conta" product="Foxy Commerce">
    @if (! $open)
        <div>
            <h2 style="margin:0 0 4px">Cadastro em breve</h2>
            <p class="muted" style="margin:0">O Foxy Commerce está em fase de lançamento com os primeiros lojistas. Se você recebeu um convite da sua equipe, use o link do e-mail.</p>
        </div>
        <p class="helper" style="margin:0;"><a href="{{ route('commerce.login') }}">Já tenho conta</a></p>
    @else
        <div>
            <h2 style="margin:0 0 4px">Criar a sua loja</h2>
            <p class="muted" style="margin:0">{{ config('commerce.trial_days') }} dias grátis, sem cartão. Depois você convida a sua equipe.</p>
        </div>
        <form method="POST" action="{{ route('commerce.signup') }}" class="stack">
            @csrf
            <div>
                <label for="store_name">Nome da loja</label>
                <input id="store_name" name="store_name" value="{{ old('store_name') }}" required maxlength="80" autofocus>
                @error('store_name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="name">Seu nome</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name">
                @error('name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
                @error('email')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="whatsapp">WhatsApp da loja (opcional)</label>
                <input id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" maxlength="30" autocomplete="tel">
            </div>
            <div>
                <label for="password">Senha (mínimo 8 caracteres)</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
                @error('password')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="password_confirmation">Repita a senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button type="submit">Criar loja</button>
        </form>
        <p class="helper" style="margin:0;"><a href="{{ route('commerce.login') }}">Já tenho conta</a></p>
    @endif
</x-core::plain>
