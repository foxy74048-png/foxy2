<x-core::layout :title="$selection->exists ? $selection->title : 'Nova seleção'" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>
        <a class="button secondary" href="{{ route('commerce.selections') }}">Voltar às seleções</a>
        @include('commerce._add', ['label' => 'Nova seleção', 'href' => route('commerce.selections.create')])
    </x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    @if (! $store?->isPublished())
        <div class="alert info" style="margin-bottom:16px;">A loja ainda não está publicada: o link da seleção só abre depois de publicar o catálogo em
            @if (\App\Commerce\Access::allows($currentMembership, 'commerce.store.manage'))<a href="{{ route('commerce.store.settings') }}">Loja própria</a>@else Loja própria (peça a quem cuida da loja)@endif.</div>
    @endif

    @if ($url && $selection->active)
        <div class="card" style="margin-bottom:16px;">
            <b>Link da seleção</b>
            <div class="helper" style="margin:4px 0 10px;word-break:break-all;">{{ $url }}</div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="button" href="https://wa.me/?text={{ rawurlencode($selection->title.":\n".$url) }}" target="_blank" rel="noopener" title="Abre o WhatsApp para você escolher para quem mandar">Mandar no WhatsApp</a>
                <a class="button secondary" href="{{ $url }}" data-copy="{{ $url }}" onclick="if (navigator.clipboard) { event.preventDefault(); navigator.clipboard.writeText(this.dataset.copy); this.textContent = 'Link copiado'; }">Copiar link</a>
                <a class="button secondary" href="{{ $url }}" target="_blank" rel="noopener">Ver como a cliente vê</a>
            </div>
            <div class="helper" style="margin-top:8px;">Mudou as peças? Salve abaixo: o link continua o mesmo e já mostra as novas.</div>
        </div>
    @elseif ($url)
        <div class="alert info" style="margin-bottom:16px;">Seleção desativada: o link não abre. <form method="POST" action="{{ route('commerce.selections.toggle', $selection) }}" style="display:inline;">@csrf<button type="submit" class="secondary">Reativar</button></form></div>
    @endif

    <form method="POST" action="{{ $selection->exists ? route('commerce.selections.update', $selection) : route('commerce.selections.store') }}" class="stack">
        @csrf
        <div class="card form-grid" style="align-items:end;">
            <div style="grid-column: span 2;">
                <label for="title">Nome da seleção</label>
                <input id="title" name="title" value="{{ old('title', $selection->title) }}" required maxlength="120" placeholder="Ex.: Novidades da semana · Tamanho G para a Bruna">
                <div class="helper">É o título que a cliente vê no alto da página.</div>
            </div>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="only_in_stock" value="1" style="width:auto;" @checked(old('only_in_stock', $selection->only_in_stock))> Esconder do link o que esgotar</label>
        </div>

        <div class="card">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:12px;">
                <div style="flex:1;min-width:200px;"><label for="find">Procurar peça</label><input id="find" placeholder="Nome ou SKU" autocomplete="off"></div>
                <div style="min-width:180px;"><label for="cat">Categoria</label><select id="cat"><option value="">Todas</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->fullName() }}</option>@endforeach</select></div>
                <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" id="with-stock" style="width:auto;"> Só com estoque</label>
            </div>
            @error('products')<div class="alert error" style="margin-bottom:12px;">{{ $message }}</div>@enderror
            <div class="helper" style="margin-bottom:8px;"><b id="count">0</b> peça(s) marcada(s).</div>
            @if ($products->isEmpty())
                <div class="empty">Nenhum produto ativo ainda. Cadastre em <a href="{{ route('commerce.products') }}">Produtos</a>.</div>
            @else
                <div class="table-wrap"><table>
                    <thead><tr><th style="width:40px;"></th><th style="width:56px;"></th><th>Peça</th><th>Preço</th><th style="text-align:right;">Em estoque</th></tr></thead>
                    <tbody>
                        @php($marked = old('products', $chosen))
                        @foreach ($products as $p)
                            @php($stock = $stockOf($p))
                            @php($prices = $p->variants->pluck('price_cents'))
                            <tr data-pick data-name="{{ mb_strtolower($p->name.' '.$p->variants->pluck('sku')->implode(' ')) }}" data-cat="{{ $p->category_id }} {{ $p->category?->parent_id }}" data-stock="{{ $stock }}">
                                <td><input type="checkbox" name="products[]" value="{{ $p->id }}" id="p-{{ $p->id }}" style="width:auto;" @checked(in_array($p->id, $marked, true))></td>
                                <td>@if ($p->images->first()?->file)<img src="{{ $p->images->first()->file->url() }}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:8px;">@endif</td>
                                <td><label for="p-{{ $p->id }}" style="font-weight:600;margin:0;">{{ $p->name }}</label>@if ($p->images->isEmpty())<span class="badge danger" title="Aparece sem imagem no link">sem foto</span>@endif<div class="helper">{{ $p->category?->fullName() }}</div></td>
                                <td style="white-space:nowrap;">{{ $money($prices->min()) }}@if ($prices->min() !== $prices->max()) a {{ $money($prices->max()) }}@endif</td>
                                <td style="text-align:right;">@if ($stock === 0)<span class="badge danger">esgotado</span>@else{{ $stock }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit">{{ $selection->exists ? 'Salvar seleção' : 'Criar seleção e pegar o link' }}</button>
        </div>
    </form>

    @if ($selection->exists)
        <form method="POST" action="{{ route('commerce.selections.toggle', $selection) }}" style="margin-top:12px;" @if ($selection->active) onsubmit="return confirm('Desativar esta seleção? O link para de abrir; dá para reativar depois.');" @endif>@csrf<button type="submit" class="secondary">{{ $selection->active ? 'Desativar seleção' : 'Reativar seleção' }}</button></form>
    @endif

    <script>
        (function () {
            const rows = [...document.querySelectorAll('[data-pick]')];
            const find = document.getElementById('find'), cat = document.getElementById('cat'), withStock = document.getElementById('with-stock');
            const count = () => { document.getElementById('count').textContent = document.querySelectorAll('[data-pick] input:checked').length; };
            const filter = () => {
                const term = find.value.trim().toLowerCase();
                rows.forEach((r) => {
                    const checked = r.querySelector('input').checked; // marcada fica sempre à vista
                    const show = checked || ((!term || r.dataset.name.includes(term)) && (!cat.value || r.dataset.cat.split(' ').includes(cat.value)) && (!withStock.checked || +r.dataset.stock > 0));
                    r.style.display = show ? '' : 'none';
                });
            };
            [find, cat, withStock].forEach((el) => el.addEventListener('input', filter));
            cat.addEventListener('change', filter); withStock.addEventListener('change', filter);
            document.addEventListener('change', (e) => { if (e.target.matches('[data-pick] input')) count(); });
            count();
        })();
    </script>
</x-core::layout>
