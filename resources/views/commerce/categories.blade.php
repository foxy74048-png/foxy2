<x-core::layout title="Categorias" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')
    @php($canManage = \App\Commerce\Access::allows($currentMembership, 'commerce.catalog.manage'))
    @php($roots = $categories->whereNull('parent_id'))

    @if ($canManage)
        <form class="card form-grid" method="POST" action="{{ route('commerce.categories.store') }}" style="align-items:end;">
            @csrf
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Nova categoria</h3></div>
            <div>
                <label for="name">Nome</label>
                <input id="name" name="name" required maxlength="80" placeholder="Ex.: Camisetas">
            </div>
            <div>
                <label for="parent_id">Dentro de (opcional)</label>
                <select id="parent_id" name="parent_id">
                    <option value="">— nenhuma —</option>
                    @foreach ($roots as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            <div><button type="submit">Criar</button></div>
        </form>
    @endif

    <div class="card" style="margin-top:16px;">
        @if ($categories->isEmpty())
            <div class="empty">Nenhuma categoria ainda. Ex.: Roupas › Camisetas, Acessórios › Bonés.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Categoria</th><th>Produtos</th>@if ($canManage)<th></th>@endif</tr></thead>
                <tbody>
                    @foreach ($categories->sortBy(fn ($c) => $c->fullName()) as $c)
                        <tr>
                            <td>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('commerce.categories.update', $c) }}" style="display:flex;gap:8px;align-items:center;">
                                        @csrf
                                        @if ($c->parent)<span class="muted">{{ $c->parent->name }} ›</span>@endif
                                        <input name="name" value="{{ $c->name }}" maxlength="80" aria-label="Nome da categoria">
                                        <input type="hidden" name="parent_id" value="{{ $c->parent_id }}">
                                        <button type="submit" class="secondary">Salvar</button>
                                    </form>
                                @else
                                    {{ $c->fullName() }}
                                @endif
                            </td>
                            <td>{{ $c->products_count }}</td>
                            @if ($canManage)
                                <td>
                                    <form method="POST" action="{{ route('commerce.categories.destroy', $c) }}">
                                        @csrf
                                        <button type="submit" class="secondary">Apagar</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
