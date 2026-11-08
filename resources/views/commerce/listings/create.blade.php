<x-core::layout title="Novo anúncio" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    @if ($connections->isEmpty())
        <div class="card"><div class="empty">Conecte um marketplace primeiro em <a href="{{ route('commerce.channels') }}">Marketplaces</a>.</div></div>
    @elseif (! $product)
        {{-- Passo 1: escolher o produto --}}
        <form class="card form-grid" method="GET" action="{{ route('commerce.listings.create') }}" style="align-items:end;">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Qual produto vai anunciar?</h3></div>
            <div>
                <label for="produto">Produto</label>
                <select id="produto" name="produto" required>@foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select>
            </div>
            <div><button type="submit">Continuar</button></div>
        </form>
    @else
        {{-- Passo 2: dados do anúncio --}}
        <form method="POST" action="{{ route('commerce.listings.store') }}" class="stack">
            @csrf
            <input type="hidden" name="product_id" value="{{ $product->id }}">
            <div class="card form-grid">
                <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Anúncio de {{ $product->name }}</h3></div>
                <div>
                    <label for="connection_id">Onde anunciar</label>
                    <select id="connection_id" name="connection_id">@foreach ($connections as $c)<option value="{{ $c->id }}" @selected(old('connection_id') === $c->id)>{{ $c->label() }} · {{ $c->name }} (até {{ config("commerce.channels.{$c->channel}.title_max") }} caracteres no título)</option>@endforeach</select>
                </div>
                <div>
                    <label for="price">Preço no canal (R$)</label>
                    <input id="price" name="price" value="{{ old('price', \App\Commerce\Catalog\Money::input($product->variants->first()?->price_cents)) }}" inputmode="decimal" required>
                    <div class="helper">Dica: no marketplace, some a taxa do canal ao preço da loja.</div>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label for="title">Título</label>
                    <input id="title" name="title" value="{{ old('title', $product->name) }}" maxlength="150" required>
                    <div class="helper">Comece pelo que o comprador digita na busca: tipo de produto, marca, modelo, material.</div>
                </div>
                <div>
                    <label for="external_category">Categoria no canal (código, opcional)</label>
                    <input id="external_category" name="external_category" value="{{ old('external_category') }}" maxlength="80">
                </div>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">Variantes no anúncio</h3>
                <div class="table-wrap"><table>
                    <thead><tr><th style="width:40px;"></th><th>Variante</th><th>SKU</th><th style="text-align:right;">Disponível</th></tr></thead>
                    <tbody>
                        @foreach ($product->variants->where('status', 'active') as $v)
                            <tr>
                                <td><input type="checkbox" name="variants[]" value="{{ $v->id }}" checked style="width:auto;" aria-label="Incluir {{ $v->sku }}"></td>
                                <td>{{ $v->label() ?: $product->name }}</td>
                                <td>{{ $v->sku }}</td>
                                <td style="text-align:right;">{{ $available[$v->id] ?? 0 }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <p class="helper">O SKU vai junto para o canal: é por ele que os pedidos voltam e baixam o estoque certo.</p>
            </div>

            <div style="display:flex;gap:8px;">
                <button type="submit" name="publish" value="1">Salvar e publicar</button>
                <button type="submit" class="secondary">Salvar rascunho</button>
            </div>
        </form>
    @endif
</x-core::layout>
