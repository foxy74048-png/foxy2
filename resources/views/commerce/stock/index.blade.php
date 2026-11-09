<x-core::layout title="Estoque" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>@include('commerce.export.buttons')</x-slot:actions>

    @include('commerce.status')
    @php($canAdjust = \App\Commerce\Access::allows($currentMembership, 'commerce.stock.adjust'))
    @include('commerce.stock._tabs', ['tab' => 'posicao'])
    @php($labels = ['negativo' => ['Negativo', 'danger'], 'zerado' => ['Zerado', 'danger'], 'baixo' => ['Baixo', ''], 'ok' => ['OK', 'success']])

    <div class="grid cards" style="margin-top:0;">
        <a class="card" href="{{ route('commerce.stock', ['filtro' => 'baixo']) }}"><div class="muted">Baixo ou zerado</div><h2 style="margin:4px 0;">{{ ($counts['baixo'] ?? 0) + ($counts['zerado'] ?? 0) + ($counts['negativo'] ?? 0) }}</h2></a>
        <a class="card" href="{{ route('commerce.stock', ['filtro' => 'negativo']) }}"><div class="muted">Negativo (vendeu sem ter)</div><h2 style="margin:4px 0;color:var(--danger-text);">{{ $counts['negativo'] ?? 0 }}</h2></a>
    </div>


    <form class="card form-grid" method="GET" action="{{ route('commerce.stock') }}" style="margin-top:16px;grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); align-items:end;">
        <div>
            <label for="busca">Nome ou SKU</label>
            <input id="busca" name="busca" value="{{ $filters['busca'] ?? '' }}">
        </div>
        <div>
            <label for="filtro">Mostrar</label>
            <select id="filtro" name="filtro">
                <option value="">Tudo</option>
                <option value="baixo" @selected(($filters['filtro'] ?? '') === 'baixo')>Baixo, zerado ou negativo</option>
                <option value="zerado" @selected(($filters['filtro'] ?? '') === 'zerado')>Zerado ou negativo</option>
                <option value="negativo" @selected(($filters['filtro'] ?? '') === 'negativo')>Só negativo</option>
            </select>
        </div>
        <div><button type="submit">Filtrar</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        @if ($rows->isEmpty())
            <div class="empty">Nada por aqui. O estoque aparece para as variantes ativas do <a href="{{ route('commerce.products') }}">catálogo</a>.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>SKU</th><th>Produto</th><th>Físico</th><th>Reservado</th><th>Disponível</th><th>Mínimo</th><th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td><a href="{{ route('commerce.stock.show', $r['variant']) }}"><strong>{{ $r['variant']->sku }}</strong></a></td>
                            <td>{{ $r['variant']->fullName() }} @if ($r['variant']->is_kit)<span class="badge">kit</span>@endif</td>
                            <td>{{ $r['variant']->is_kit ? '—' : ($r['level']?->on_hand ?? 0) }}</td>
                            <td>{{ $r['variant']->is_kit ? '—' : ($r['level']?->reserved ?? 0) }}</td>
                            <td><strong>{{ $r['available'] }}</strong></td>
                            <td>{{ $r['level']?->min_stock ?? '—' }}</td>
                            <td><span @class(['badge', $labels[$r['status']][1] => $labels[$r['status']][1] !== ''])>{{ $labels[$r['status']][0] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>
</x-core::layout>
