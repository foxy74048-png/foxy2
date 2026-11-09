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
            @php($mode = old('kit_mode', request('modo') === 'conjunto' ? 'conjunto' : 'fixo'))
            <div class="card">
                <h3 style="margin-top:0;">Que tipo de kit?</h3>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;"><input type="radio" name="kit_mode" value="fixo" @checked($mode === 'fixo') style="width:auto;" data-kit-mode> <span><strong>Kit fixo</strong><br><span class="helper">Sempre as mesmas peças (ex.: 2 camisetas brancas M).</span></span></label>
                    <label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;"><input type="radio" name="kit_mode" value="conjunto" @checked($mode === 'conjunto') style="width:auto;" data-kit-mode> <span><strong>Conjunto com tamanho de cada peça</strong><br><span class="helper">A cliente escolhe o tamanho de cada peça (ex.: sutiã M com calcinha G).</span></span></label>
                </div>
            </div>

            <div class="card" data-kit-section="fixo" @if ($mode !== 'fixo') hidden @endif>
                <h3 style="margin-top:0;">Peças do kit</h3>
                @include('commerce.products._kit-pieces', ['rows' => collect(old('components', array_fill(0, 5, [])))->map(fn ($r) => [$r['sku'] ?? '', $r['quantity'] ?? 1])->values()->all()])
            </div>

            <div class="card" data-kit-section="conjunto" @if ($mode !== 'conjunto') hidden @endif>
                <h3 style="margin-top:0;">Peças do conjunto</h3>
                <p class="helper" style="margin-top:0;">Escolha os produtos que formam o conjunto (cada um já cadastrado com a sua grade). O sistema cria uma opção para cada combinação de tamanhos, e cada venda baixa o estoque das peças certas.</p>
                <div class="table-wrap"><table>
                    <thead><tr><th>Produto</th><th style="width:200px;">Nome da peça no conjunto</th><th style="width:110px;">Quantidade</th></tr></thead>
                    <tbody>
                        @foreach (range(0, 2) as $i)
                            <tr>
                                <td><select name="set[pieces][{{ $i }}][product_id]" aria-label="Produto da peça {{ $i + 1 }}"><option value="">{{ $i < 2 ? 'Escolha o produto' : '(opcional) terceira peça' }}</option>@foreach ($setProducts as $p)<option value="{{ $p->id }}" @selected(old("set.pieces.$i.product_id") === $p->id)>{{ $p->name }} ({{ $p->variants->where('status', 'active')->count() }} variantes)</option>@endforeach</select></td>
                                <td><input name="set[pieces][{{ $i }}][label]" value="{{ old("set.pieces.$i.label") }}" maxlength="30" placeholder="{{ ['Ex.: Sutiã', 'Ex.: Calcinha', 'Ex.: Cinta-liga'][$i] }}" aria-label="Nome da peça {{ $i + 1 }}"></td>
                                <td><input name="set[pieces][{{ $i }}][quantity]" type="number" min="1" max="99" value="{{ old("set.pieces.$i.quantity", 1) }}" aria-label="Quantidade da peça {{ $i + 1 }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px;">
                    <label style="display:flex;gap:8px;align-items:center;font-weight:400;margin:0;"><input type="checkbox" name="set[match]" value="1" @checked(old('set.match', true)) style="width:auto;"> Combinar só peças com a mesma</label>
                    <input name="set[same]" value="{{ old('set.same', 'Cor') }}" maxlength="30" style="max-width:160px;" aria-label="Característica que precisa ser igual">
                    <span class="helper">Ex.: Cor ou Estampa: sutiã preto só com calcinha preta.</span>
                </div>
            </div>
            <script>
                document.querySelectorAll('[data-kit-mode]').forEach(function (radio) {
                    radio.addEventListener('change', function () {
                        document.querySelectorAll('[data-kit-section]').forEach(function (s) { s.hidden = s.dataset.kitSection !== radio.value; });
                    });
                });
            </script>
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
