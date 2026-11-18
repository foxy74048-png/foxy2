<x-core::plain title="Criar conta" product="Foxy Finanças">
    @if (! $open)
        <div>
            <h2 style="margin:0 0 4px">Cadastro em breve</h2>
            <p class="muted" style="margin:0">O Foxy Finanças está em fase de testes com as primeiras pessoas. Se você recebeu um convite para uma carteira, use o link do e-mail.</p>
        </div>
        <p class="helper" style="margin:0;"><a href="{{ route('finance.login') }}">Já tenho conta</a></p>
    @else
        <div>
            <h2 style="margin:0 0 4px">Saiba para onde vai o seu dinheiro</h2>
            <p class="muted" style="margin:0">Grátis para começar, sem cartão. Você já começa com a sua carteira pessoal.</p>
        </div>
        <form method="POST" action="{{ route('finance.signup') }}" class="stack">
            @csrf
            <div>
                <label for="name">Seu nome</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autofocus autocomplete="name">
                @error('name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="email">E-mail</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username">
                @error('email')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
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

            {{-- Negócio: aberto já no cadastro, sem esconder (MEI, ou sócios dividindo pró-labore e lucro) --}}
            <fieldset class="stack" style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin:0;">
                <legend style="padding:0 6px;font-weight:600;">Você tem um negócio?</legend>
                <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="has_business" value="1" @checked(old('has_business')) style="width:auto;"> Sim, quero uma carteira separada para o negócio</label>
                <div>
                    <label for="business_name">Nome do negócio</label>
                    <input id="business_name" name="business_name" value="{{ old('business_name') }}" maxlength="80" placeholder="Ex.: Ateliê da Ana">
                    @error('business_name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
                </div>
                <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="is_mei" value="1" @checked(old('is_mei')) style="width:auto;"> É MEI</label>
                <div class="helper">Depois você convida o sócio e acompanha pró-labore, retiradas e o lucro de cada um. Dá para criar a carteira do negócio mais tarde também.</div>
            </fieldset>

            <button type="submit">Criar conta</button>
        </form>
        <p class="helper" style="margin:0;"><a href="{{ route('finance.login') }}">Já tenho conta</a></p>
    @endif
</x-core::plain>
