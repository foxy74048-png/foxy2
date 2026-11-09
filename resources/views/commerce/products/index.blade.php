<x-core::layout title="Produtos" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    @php($canManage = \App\Commerce\Access::allows($currentMembership, 'commerce.catalog.manage'))
    <x-slot:actions>
        @include('commerce.export.buttons')
        @if ($canManage)
            <a class="button secondary" href="{{ route('commerce.products.create', ['tipo' => 'kit']) }}">Novo kit</a>
            <a class="button" href="{{ route('commerce.products.create') }}">Novo produto</a>
        @endif
    </x-slot:actions>

    @include('commerce.status')

    <form class="card form-grid" method="GET" action="{{ route('commerce.products') }}" style="grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div>
            <label for="busca">Nome ou SKU</label>
            <input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}" placeholder="Buscar">
        </div>
        <div>
            <label for="categoria">Categoria</label>
            <select id="categoria" name="categoria">
                <option value="">Todas</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(($filters['categoria'] ?? '') === $c->id)>{{ $c->fullName() }}</option>@endforeach
            </select>
        </div>
        <div>
            <label for="situacao">Situação</label>
            <select id="situacao" name="situacao">
                <option value="">Ativos e rascunhos</option>
                @foreach (\App\Commerce\Catalog\Product::STATUSES as $k => $label)<option value="{{ $k }}" @selected(($filters['situacao'] ?? '') === $k)>{{ $label }}</option>@endforeach
            </select>
        </div>
        <div><button type="submit">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($products->isEmpty())
            <div class="empty">Nenhum produto encontrado.@if ($canManage) Comece por <a href="{{ route('commerce.products.create') }}">Novo produto</a>: com a grade de tamanho e cor, o sistema cria todas as variantes de uma vez.@endif</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th></th><th>Produto</th><th>Categoria</th><th>Variantes</th><th>Preço</th><th>Situação</th>@if ($canManage)<th style="text-align:right;">Ações</th>@endif</tr></thead>
                <tbody>
                    @foreach ($products as $p)
                        @php($prices = $p->variants->pluck('price_cents'))
                        <tr>
                            <td style="width:56px;">@if ($p->images->first()?->file)<img src="{{ $p->images->first()->file->url() }}" alt="" style="width:48px;height:48px;object-fit:cover;border-radius:8px;">@endif</td>
                            <td>
                                @if ($canManage)<a href="{{ route('commerce.products.edit', $p) }}"><strong>{{ $p->name }}</strong></a>@else<strong>{{ $p->name }}</strong>@endif
                                @if ($p->isKit())<span class="badge">kit</span>@endif
                                <div class="helper">{{ $p->variants->count() === 1 ? $p->variants->first()->sku : $p->variants->first()?->sku.' …' }}</div>
                            </td>
                            <td>{{ $p->category?->fullName() ?? '—' }}</td>
                            <td>{{ $p->variants->count() }}</td>
                            <td style="white-space:nowrap;">{{ \App\Commerce\Catalog\Money::format($prices->min()) }}@if ($prices->min() !== $prices->max()) a {{ \App\Commerce\Catalog\Money::format($prices->max()) }}@endif</td>
                            <td><span @class(['badge', 'success' => $p->status === 'active', 'danger' => $p->status === 'archived'])>{{ \App\Commerce\Catalog\Product::STATUSES[$p->status] ?? $p->status }}</span></td>
                            @if ($canManage)
                                <td style="text-align:right;white-space:nowrap;">
                                    <a class="button secondary" href="{{ route('commerce.products.edit', $p) }}">Editar</a>
                                    <form method="POST" action="{{ route('commerce.products.archive', $p) }}" style="display:inline;" @if ($p->status !== 'archived') onsubmit="return confirm('Desativar {{ e(addslashes($p->name)) }}? Some das listas e da loja; o histórico fica e dá para reativar.');" @endif>
                                        @csrf<button type="submit" class="secondary">{{ $p->status === 'archived' ? 'Reativar' : 'Desativar' }}</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="margin-top:12px;">{{ $products->links() }}</div>
        @endif
    </div>
</x-core::layout>
