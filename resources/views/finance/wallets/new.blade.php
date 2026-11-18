<x-core::plain title="Nova carteira" product="Foxy Finanças">
    @if ($canOpen)
        <div>
            <h2 style="margin:0 0 4px">{{ $hasWallets ? 'Abrir outra carteira' : 'Abrir a sua carteira' }}</h2>
            <p class="muted" style="margin:0">Cada carteira tem as próprias contas, cartões e lançamentos. Use uma para a vida pessoal (ou da casa) e outra para o negócio.</p>
        </div>
        <form method="POST" action="{{ route('finance.wallets.create') }}" class="stack">
            @csrf
            <div>
                <label for="kind">Tipo</label>
                <select id="kind" name="kind">
                    @foreach (config('finance.wallet_kinds') as $key => $label)<option value="{{ $key }}" @selected(old('kind', $hasWallets ? 'negocio' : 'pessoal') === $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="name">Nome da carteira</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="80" autofocus placeholder="Ex.: Casa, Ateliê da Ana">
                @error('name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="is_mei" value="1" @checked(old('is_mei')) style="width:auto;"> O negócio é MEI</label>
            <div class="helper">Na carteira do negócio você convida o sócio e acompanha pró-labore, retiradas e o lucro de cada um.</div>
            <button type="submit">Criar carteira</button>
        </form>
    @else
        <div>
            <h2 style="margin:0 0 4px">Você ainda não está numa carteira</h2>
            <p class="muted" style="margin:0">O Foxy Finanças está em fase de testes. Peça um convite para quem já usa: ele chega no seu e-mail.</p>
        </div>
    @endif
    @if ($hasWallets)<p class="helper" style="margin:0;"><a href="{{ route('finance.dashboard') }}">Voltar</a></p>@endif
    <form method="POST" action="{{ route('finance.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
