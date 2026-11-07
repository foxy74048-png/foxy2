<x-core::plain title="Convite" product="Foxy Commerce">
    @if (! $invitation)
        <div>
            <h2 style="margin:0 0 4px">Convite indisponível</h2>
            <p class="muted" style="margin:0">Este convite já foi usado, foi cancelado ou venceu. Peça um novo para quem convidou você.</p>
        </div>
        <p class="helper" style="margin:0;"><a href="{{ route('commerce.login') }}">Entrar</a></p>
    @else
        <div>
            <h2 style="margin:0 0 4px">Equipe da {{ $storeName }}</h2>
            <p class="muted" style="margin:0">Você foi convidado(a) como <strong>{{ $role }}</strong>. O convite é para <strong>{{ $invitation->email }}</strong>.</p>
        </div>
        @error('email')<div class="alert error">{{ $message }}</div>@enderror

        @if ($user)
            <form method="POST" action="{{ route('commerce.invitation.accept', $token) }}">
                @csrf
                <button type="submit" style="width:100%;">Entrar na equipe</button>
            </form>
        @elseif ($existingUser)
            <p style="margin:0;">Você já tem login no Foxy. Entre com a sua senha para aceitar.</p>
            <form method="POST" action="{{ route('commerce.invitation.accept', $token) }}">
                @csrf
                <button type="submit" style="width:100%;">Entrar e aceitar</button>
            </form>
        @else
            <p style="margin:0;">Crie o seu acesso. O login é só seu: não compartilhe a senha.</p>
            <form method="POST" action="{{ route('commerce.invitation.accept', $token) }}" class="stack">
                @csrf
                <div>
                    <label for="name">Seu nome</label>
                    <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autofocus autocomplete="name">
                    @error('name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
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
                <button type="submit">Criar acesso e entrar</button>
            </form>
        @endif
    @endif
</x-core::plain>
