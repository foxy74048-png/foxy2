{{-- Produto acrescentado à mão na conferência da remessa (a leitura do documento não pegou). --}}
<div class="card" data-group data-new-group>
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
        <span class="badge">produto acrescentado</span>
        <button type="button" class="button secondary" data-remove="[data-new-group]" aria-label="Remover produto" title="Remover">×</button>
    </div>
    <div class="form-grid" style="align-items:end;margin-top:8px;">
        <div style="grid-column: span 2;">
            <label for="ng{{ $n }}-name">Produto</label>
            <input id="ng{{ $n }}-name" name="new_groups[{{ $n }}][name]" value="{{ $group['name'] ?? '' }}" required maxlength="150" placeholder="Ex.: Conjunto Renda Fio Duplo">
            <div class="helper">Se já existe um produto com este nome, as peças entram nele.</div>
        </div>
        <div>
            <label for="ng{{ $n }}-mode">Origem</label>
            <select id="ng{{ $n }}-mode" name="new_groups[{{ $n }}][mode]" data-mode>@foreach (\App\Commerce\Supply\SupplyBatch::MODES as $k => $l)<option value="{{ $k }}" @selected(($group['mode'] ?? 'consignado') === $k)>{{ $k === 'compra' ? 'Comprado' : 'Consignado' }}</option>@endforeach</select>
        </div>
        <div>
            <label for="ng{{ $n }}-cat">Categoria</label>
            <select id="ng{{ $n }}-cat" name="new_groups[{{ $n }}][category_id]"><option value="">— sem categoria —</option>@foreach ($categories as $cat)<option value="{{ $cat->id }}" @selected(($group['category_id'] ?? null) === $cat->id)>{{ $cat->fullName() }}</option>@endforeach</select>
        </div>
        <div><label for="ng{{ $n }}-sale">Preço de venda (R$)</label><input id="ng{{ $n }}-sale" name="new_groups[{{ $n }}][sale]" value="{{ $group['sale'] ?? '' }}" inputmode="decimal" required>@error("new_groups.$n.sale")<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror</div>
        <div><label for="ng{{ $n }}-cost">Custo (R$)</label><input id="ng{{ $n }}-cost" name="new_groups[{{ $n }}][cost]" value="{{ $group['cost'] ?? '' }}" inputmode="decimal" required><div class="helper">o que você paga à fornecedora por peça</div></div>
    </div>
    <div class="table-wrap" style="margin-top:12px;"><table>
        <thead><tr><th></th><th style="width:170px;">Cor</th><th style="width:110px;">Tamanho</th><th style="width:150px;">Cód. de barras</th><th style="width:100px;">Qtd.</th></tr></thead>
        <tbody>
            @foreach (($group['lines'] ?? [0 => []]) as $l => $line)
                @include('commerce.supply.extra-line', ['prefix' => "new_groups[$n][lines][$l]", 'line' => $line])
            @endforeach
        </tbody>
    </table></div>
    <button type="button" class="button secondary" data-add-line="new_groups[{{ $n }}][lines]" style="margin-top:8px;">+ Adicionar cor/tamanho</button>
</div>
