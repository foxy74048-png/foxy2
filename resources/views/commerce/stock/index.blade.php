<x-core::layout title="Estoque" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')
    @php($canAdjust = \App\Commerce\Access::allows($currentMembership, 'commerce.stock.adjust'))
    @php($labels = ['negativo' => ['Negativo', 'danger'], 'zerado' => ['Zerado', 'danger'], 'baixo' => ['Baixo', ''], 'ok' => ['OK', 'success']])

    <div class="grid cards">
        <a class="card" href="{{ route('commerce.stock', ['filtro' => 'baixo']) }}"><div class="muted">Baixo ou zerado</div><h2 style="margin:4px 0;">{{ ($counts['baixo'] ?? 0) + ($counts['zerado'] ?? 0) + ($counts['negativo'] ?? 0) }}</h2></a>
        <a class="card" href="{{ route('commerce.stock', ['filtro' => 'negativo']) }}"><div class="muted">Negativo (vendeu sem ter)</div><h2 style="margin:4px 0;color:var(--danger-text);">{{ $counts['negativo'] ?? 0 }}</h2></a>
    </div>

    @if ($canAdjust)
        <form class="card" method="POST" action="{{ route('commerce.stock.receive') }}" style="margin-top:16px;">
            @csrf
            <h3 style="margin-top:0;">Entrada de mercadoria</h3>
            <p class="helper" style="margin-top:0;">Chegou mercadoria? Digite o SKU e a quantidade de cada item. Linhas vazias são ignoradas.</p>
            <datalist id="stock-skus">@foreach ($rows->where('variant.is_kit', false) as $r)<option value="{{ $r['variant']->sku }}">{{ $r['variant']->fullName() }}</option>@endforeach</datalist>
            <div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));">
                @for ($i = 0; $i < 6; $i++)
                    <div style="display:flex;gap:8px;">
                        <input name="items[{{ $i }}][sku]" list="stock-skus" placeholder="SKU" maxlength="55" aria-label="SKU {{ $i + 1 }}">
                        <input name="items[{{ $i }}][quantity]" type="number" min="1" placeholder="Qtd." style="max-width:90px;" aria-label="Quantidade {{ $i + 1 }}">
                    </div>
                @endfor
            </div>
            <div style="display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap;">
                <input name="reason" maxlength="200" placeholder="Observação (ex.: NF 1234 do fornecedor)" style="max-width:360px;">
                <button type="submit">Registrar entrada</button>
            </div>
        </form>
    @endif

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
