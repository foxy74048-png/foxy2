<x-core::layout :title="$product->name" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>
        <a class="button secondary" href="{{ route('commerce.products') }}">Voltar aos produtos</a>
        @if ($product->status === 'active' && \App\Commerce\Access::allows($currentMembership, 'commerce.channels.manage'))<a class="button" href="{{ route('commerce.listings.create', ['produto' => $product->id]) }}">Anunciar</a>@endif
    </x-slot:actions>

    @include('commerce.status')
    @if ($product->status === 'archived')<div class="alert info" style="margin-bottom:16px;">Produto desativado: não aparece nas listas nem vai para os canais. Use "Reativar produto" no fim da página para voltar a vender.</div>@endif

    @if ($product->isSet())
        @php($setPieces = collect($product->kit_set['pieces'] ?? []))
        @php($setNames = \App\Commerce\Catalog\Product::whereIn('id', $setPieces->pluck('product_id'))->pluck('name', 'id'))
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin-top:0;">Peças do conjunto</h3>
            <ul style="margin:0 0 12px;padding-left:20px;line-height:1.8;">
                @foreach ($setPieces as $piece)
                    <li><strong>{{ $piece['label'] }}</strong>: {{ $setNames[$piece['product_id']] ?? 'produto removido' }}{{ ($piece['quantity'] ?? 1) > 1 ? ' × '.$piece['quantity'] : '' }}</li>
                @endforeach
            </ul>
            <p class="helper">{{ $product->kit_set['same'] ? 'Só combina peças com a mesma '.$product->kit_set['same'].'.' : 'Combina todos os tamanhos entre si.' }} Cada combinação acima baixa o estoque das peças dela. Não vai vender alguma? Desmarque "Ativa" na lista.</p>
            <form method="POST" action="{{ route('commerce.products.set', $product) }}">
                @csrf
                <button type="submit" class="secondary">Criar combinações que faltam</button>
                <span class="helper">Use depois de cadastrar um tamanho ou cor nova numa das peças.</span>
            </form>
        </div>
    @endif

    {{-- Variantes primeiro: é o que mais se mexe (SKU, preço). --}}
    <form class="card" method="POST" action="{{ route('commerce.products.variants', $product) }}">
        @csrf
        <h3 style="margin-top:0;">{{ $product->isSet() ? 'Combinações do conjunto' : ($product->isKit() ? 'Kit' : 'Variantes') }} <span class="muted">({{ $product->variants->count() }})</span></h3>
        @if ($product->variants->count() > 8)
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <input type="search" placeholder="Filtrar (ex.: Preto, 42, GG)" style="max-width:240px;" aria-label="Filtrar variantes" data-variant-filter>
                <span class="muted" style="margin-left:8px;">Mesmo preço para as que aparecem:</span>
                <input inputmode="decimal" placeholder="129,90" style="max-width:110px;" aria-label="Preço para todas" data-bulk-price>
                <button type="button" class="secondary" data-bulk-apply>Aplicar</button>
                <span class="helper">Depois clique em Salvar.</span>
            </div>
        @endif
        <div class="table-wrap"><table>
            <thead><tr>@if ($product->hasGrid())<th>Variante</th>@endif<th>SKU</th><th>Preço (R$)</th>@unless ($product->isKit())<th>Custo (R$)</th>@endunless<th>Código de barras</th><th>Ativa</th><th>Disponível</th></tr></thead>
            <tbody>
                @foreach ($product->variants as $v)
                    <tr data-variant-row="{{ mb_strtolower($v->label().' '.$v->sku) }}">
                        @if ($product->hasGrid())<td><strong>{{ $v->label() }}</strong></td>@endif
                        <td><input name="variants[{{ $v->id }}][sku]" value="{{ old("variants.{$v->id}.sku", $v->sku) }}" maxlength="55" required aria-label="SKU {{ $v->label() }}"></td>
                        <td><input name="variants[{{ $v->id }}][price]" value="{{ old("variants.{$v->id}.price", \App\Commerce\Catalog\Money::input($v->price_cents)) }}" inputmode="decimal" required style="max-width:110px;" aria-label="Preço {{ $v->label() }}"></td>
                        @unless ($product->isKit())<td><input name="variants[{{ $v->id }}][cost]" value="{{ old("variants.{$v->id}.cost", \App\Commerce\Catalog\Money::input($v->cost_cents)) }}" inputmode="decimal" style="max-width:110px;" aria-label="Custo {{ $v->label() }}"></td>@endunless
                        <td><input name="variants[{{ $v->id }}][gtin]" value="{{ old("variants.{$v->id}.gtin", $v->gtin) }}" inputmode="numeric" maxlength="14" style="max-width:150px;" aria-label="Código de barras {{ $v->label() }}"></td>
                        <td>
                            <select name="variants[{{ $v->id }}][status]" style="min-width:92px;" aria-label="Situação {{ $v->label() }}">
                                <option value="active" @selected($v->status === 'active')>Sim</option>
                                <option value="inactive" @selected($v->status === 'inactive')>Não</option>
                            </select>
                        </td>
                        <td><a href="{{ route('commerce.stock.show', $v) }}">{{ $available[$v->id] ?? 0 }}</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <div style="margin-top:12px;"><button type="submit">Salvar {{ $product->isSet() ? 'combinações' : 'variantes' }}</button></div>
    </form>
    <script>
        (function () {
            const filter = document.querySelector('[data-variant-filter]');
            if (!filter) return;
            const rows = [...document.querySelectorAll('[data-variant-row]')];
            filter.addEventListener('input', function () {
                const terms = filter.value.toLowerCase().split(/\s+/).filter(Boolean);
                rows.forEach(function (r) { r.hidden = !terms.every(function (t) { return r.dataset.variantRow.includes(t); }); });
            });
            document.querySelector('[data-bulk-apply]').addEventListener('click', function () {
                const price = document.querySelector('[data-bulk-price]').value.trim();
                if (!price) return;
                rows.filter(function (r) { return !r.hidden; }).forEach(function (r) { r.querySelector('input[name$="[price]"]').value = price; });
            });
        })();
    </script>

    @if ($product->hasGrid() && ! $product->isKit())
        <form class="card form-grid" method="POST" action="{{ route('commerce.products.grid', $product) }}" style="margin-top:16px;align-items:end;">
            @csrf
            <div style="grid-column: 1 / -1;">
                <h3 style="margin:0;">Acrescentar à grade</h3>
                <p class="helper" style="margin:4px 0 0;">Ex.: um tamanho novo. O sistema cria só as combinações que faltam.</p>
            </div>
            @foreach ($product->option_names as $i => $name)
                <div>
                    <label for="values-{{ $i }}">Novos valores de {{ $name }}</label>
                    <input id="values-{{ $i }}" name="values[{{ $i }}]" maxlength="500" placeholder="Separados por vírgula">
                </div>
            @endforeach
            <div>
                <label for="grid-price">Preço das novas (R$)</label>
                <input id="grid-price" name="price" inputmode="decimal" placeholder="{{ \App\Commerce\Catalog\Money::input($product->variants->first()?->price_cents) }}">
            </div>
            <div><button type="submit" class="secondary">Criar variantes</button></div>
        </form>
    @endif

    @if ($product->isKit() && ! $product->isSet())
        @php($kitVariant = $product->variants->first())
        <form class="card" method="POST" action="{{ route('commerce.products.kit', $product) }}" style="margin-top:16px;">
            @csrf
            <h3 style="margin-top:0;">Peças do kit</h3>
            @php($rows = $kitVariant->components->map(fn ($c) => [$c->component->sku, $c->quantity])->values()->all())
            @include('commerce.products._kit-pieces', ['rows' => array_pad($rows, count($rows) + 3, ['', 1])])
            <button type="submit">Salvar peças</button>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Fotos <span class="muted">({{ $product->images->count() }}/{{ \App\Commerce\Http\ProductController::MAX_IMAGES }})</span></h3>
        @if ($product->images->isNotEmpty())
            <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px;">
                @foreach ($product->images as $img)
                    <div style="text-align:center;">
                        <img src="{{ $img->file->url() }}" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:12px;display:block;">
                        @if ($loop->first)<div class="helper">capa</div>@endif
                        <form method="POST" action="{{ route('commerce.products.images.delete', [$product, $img->id]) }}">
                            @csrf
                            <button type="submit" class="secondary" style="margin-top:4px;">Apagar</button>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('commerce.products.images', $product) }}" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            @csrf
            <input type="file" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required aria-label="Escolher fotos">
            <button type="submit" class="secondary">Enviar fotos</button>
            <span class="helper">JPG, PNG ou WEBP, até 5 MB cada. Fundo branco ajuda nos marketplaces.</span>
        </form>
    </div>

    <form method="POST" action="{{ route('commerce.products.update', $product) }}" style="margin-top:16px;">
        @csrf
        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Dados do produto</h3></div>
            @include('commerce.products._fields')
            <div style="grid-column: 1 / -1;"><button type="submit">Salvar produto</button></div>
        </div>
    </form>

    <form method="POST" action="{{ route('commerce.products.archive', $product) }}" style="margin-top:16px;">
        @csrf
        <button type="submit" class="secondary">{{ $product->status === 'archived' ? 'Reativar produto' : 'Desativar produto' }}</button>
    </form>
</x-core::layout>
