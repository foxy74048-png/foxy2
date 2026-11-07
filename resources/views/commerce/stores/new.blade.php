<x-core::plain title="Nova loja" product="Foxy Commerce">
    @if (config('commerce.signup_open') || auth()->user()->isStaff())
        <div>
            <h2 style="margin:0 0 4px">{{ $hasStores ? 'Abrir outra loja' : 'Abrir a sua loja' }}</h2>
            @unless ($hasStores)<p class="muted" style="margin:0">Você não está na equipe de nenhuma loja. Abra a sua ou peça um convite para quem cuida da loja.</p>@endunless
        </div>
        <form method="POST" action="{{ route('commerce.stores.create') }}" class="stack">
            @csrf
            <div>
                <label for="store_name">Nome da loja</label>
                <input id="store_name" name="store_name" value="{{ old('store_name') }}" required maxlength="80" autofocus>
                @error('store_name')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="whatsapp">WhatsApp da loja (opcional)</label>
                <input id="whatsapp" name="whatsapp" value="{{ old('whatsapp') }}" maxlength="30">
            </div>
            <button type="submit">Abrir loja</button>
        </form>
    @else
        <div>
            <h2 style="margin:0 0 4px">Você ainda não está numa loja</h2>
            <p class="muted" style="margin:0">Peça um convite para quem cuida da loja: ele chega no seu e-mail.</p>
        </div>
    @endif
    @if ($hasStores)<p class="helper" style="margin:0;"><a href="{{ route('commerce.stores.choose') }}">Voltar para as minhas lojas</a></p>@endif
    <form method="POST" action="{{ route('commerce.logout') }}">
        @csrf
        <button type="submit" class="secondary" style="width:100%;">Sair</button>
    </form>
</x-core::plain>
