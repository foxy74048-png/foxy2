<x-core::layout title="Ajustes da carteira" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>
    <x-slot:actions>@include('finance._add', ['label' => 'Nova carteira', 'href' => route('finance.wallets.new')])</x-slot:actions>

    @include('finance.status')

    <form class="card stack" method="POST" action="{{ route('finance.wallet.update') }}" style="max-width:560px;">
        @csrf
        <div>
            <label for="name">Nome da carteira</label>
            <input id="name" name="name" value="{{ old('name', $wallet->name) }}" required maxlength="80">
        </div>
        <div>
            <label for="kind">Tipo</label>
            <select id="kind" name="kind">
                @foreach ($kinds as $key => $label)<option value="{{ $key }}" @selected(old('kind', $wallet->kind) === $key)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <fieldset class="stack" style="border:1px solid var(--border);border-radius:var(--radius);padding:12px;margin:0;">
            <legend style="padding:0 6px;font-weight:600;">Negócio</legend>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="is_mei" value="1" @checked(old('is_mei', $wallet->is_mei)) style="width:auto;"> É MEI</label>
            <div>
                <label for="das_day">Dia de pagar o DAS</label>
                <input id="das_day" name="das_day" type="number" min="1" max="28" value="{{ old('das_day', $wallet->das_day ?? 20) }}" style="max-width:120px;">
                <div class="helper">O DAS do MEI vence todo dia 20. O lembrete entra com os lançamentos.</div>
            </div>
            <div class="helper">Só vale para carteira do tipo negócio.</div>
        </fieldset>
        <div><button type="submit">Salvar</button></div>
    </form>

    {{-- Privacidade (fatia 11) --}}
    <div class="card stack" style="max-width:560px;margin-top:16px;" id="exportar">
        <h3 style="margin:0;">Exportar tudo</h3>
        <p class="helper" style="margin:0;">Uma cópia de tudo o que esta carteira guarda: contas, categorias, regras, todos os lançamentos, orçamento, metas, quem me deve, grupos, sócios, pessoas e pontos.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a class="button" href="{{ route('finance.wallet.export', ['exportar' => 'planilha']) }}">Baixar planilha</a>
            <a class="button secondary" href="{{ route('finance.wallet.export', ['exportar' => 'pdf']) }}">Baixar PDF</a>
        </div>
    </div>

    <form class="card stack" method="POST" action="{{ route('finance.wallet.destroy') }}" style="max-width:560px;margin-top:16px;border-color:#b91c1c;" id="apagar"
          onsubmit="return confirm('Apagar a carteira {{ addslashes($wallet->name) }} e tudo o que ela tem? Não tem volta.');">
        @csrf
        <h3 style="margin:0;">Apagar esta carteira</h3>
        <p style="margin:0;">Apaga de vez <strong>{{ $wallet->name }}</strong> e tudo o que ela tem, <strong>para todas as pessoas da carteira</strong>. Exporte antes se quiser guardar uma cópia.</p>
        <div>
            <label for="password">Sua senha</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required style="max-width:280px;">
            @error('password')<div class="error">{{ $message }}</div>@enderror
        </div>
        <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="confirm" value="1" required style="width:auto;"> Entendi que não tem volta</label>
        @error('confirm')<div class="error">{{ $message }}</div>@enderror
        <div><button type="submit" class="danger">Apagar a carteira</button></div>
    </form>
</x-core::layout>
