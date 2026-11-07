<x-core::layout :title="$kit ? 'Novo kit' : 'Novo produto'" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    <form method="POST" action="{{ route('commerce.products.store') }}" class="stack">
        @csrf
        <input type="hidden" name="type" value="{{ $kit ? 'kit' : 'simple' }}">

        <div class="card form-grid">
            @include('commerce.products._fields', ['product' => null])
        </div>

        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Preço</h3></div>
            <div>
                <label for="price">Preço de venda (R$)</label>
                <input id="price" name="price" value="{{ old('price') }}" required inputmode="decimal" placeholder="79,90">
            </div>
            @unless ($kit)
                <div>
                    <label for="cost">Custo (R$, opcional)</label>
                    <input id="cost" name="cost" value="{{ old('cost') }}" inputmode="decimal" placeholder="35,00">
                </div>
            @endunless
            <div>
                <label for="sku">Início do SKU (opcional)</label>
                <input id="sku" name="sku" value="{{ old('sku') }}" maxlength="40" placeholder="Ex.: CAM-BAS">
                <div class="helper">Vazio: usamos o nome. Cada variante ganha o tamanho/cor no fim; dá para editar depois.</div>
            </div>
        </div>

        @if ($kit)
            <div class="card">
                <h3 style="margin-top:0;">Peças do kit</h3>
                @include('commerce.products._kit-pieces', ['rows' => collect(old('components', array_fill(0, 5, [])))->map(fn ($r) => [$r['sku'] ?? '', $r['quantity'] ?? 1])->values()->all()])
            </div>
        @else
            <div class="card">
                <h3 style="margin-top:0;">Grade (tamanho, cor...)</h3>
                <p class="helper" style="margin-top:0;">Produto sem variação? Deixe em branco. Com grade, o sistema cria uma variante para cada combinação (ex.: P, M, G × Azul, Preto = 6 variantes).</p>
                <div class="form-grid">
                    @foreach ([['Tamanho', 'P, M, G, GG'], ['Cor', 'Azul, Preto, Branco'], ['', '']] as $i => [$name, $example])
                        <div>
                            <label for="grid-{{ $i }}-name">Característica {{ $i + 1 }}</label>
                            <input id="grid-{{ $i }}-name" name="grid[{{ $i }}][name]" value="{{ old("grid.$i.name") }}" maxlength="30" placeholder="{{ $name ?: 'Ex.: Material' }}">
                        </div>
                        <div>
                            <label for="grid-{{ $i }}-values">Valores (separados por vírgula)</label>
                            <input id="grid-{{ $i }}-values" name="grid[{{ $i }}][values]" value="{{ old("grid.$i.values") }}" maxlength="500" placeholder="{{ $example ?: 'Ex.: Algodão, Linho' }}">
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div><button type="submit">{{ $kit ? 'Criar kit' : 'Criar produto' }}</button> <a class="button secondary" href="{{ route('commerce.products') }}">Cancelar</a></div>
    </form>
</x-core::layout>
