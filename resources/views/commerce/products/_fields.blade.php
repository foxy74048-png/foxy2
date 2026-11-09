{{-- Campos do produto (novo e edição). $product opcional; $categories obrigatória. --}}
@php($p = $product ?? null)
<div style="grid-column: 1 / -1;">
    <label for="name">Nome</label>
    <input id="name" name="name" value="{{ old('name', $p?->name) }}" required maxlength="255" placeholder="Ex.: Camiseta Básica Algodão">
</div>
<div>
    <label for="category_id">Categoria</label>
    <select id="category_id" name="category_id">
        <option value="">— sem categoria —</option>
        @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id', $p?->category_id) === $c->id)>{{ $c->fullName() }}</option>@endforeach
    </select>
    <div class="helper"><a href="{{ route('commerce.categories') }}">Gerenciar categorias</a></div>
</div>
<div>
    <label for="brand">Marca</label>
    <input id="brand" name="brand" value="{{ old('brand', $p?->brand) }}" maxlength="80">
</div>
<div>
    <label for="supply_mode">Origem</label>
    <select id="supply_mode" name="supply_mode">
        <option value="" @selected(old('supply_mode', $p?->supply_mode) === null)>Própria (estoque meu)</option>
        <option value="compra" @selected(old('supply_mode', $p?->supply_mode) === 'compra')>Comprado de fornecedora</option>
        <option value="consignado" @selected(old('supply_mode', $p?->supply_mode) === 'consignado')>Consignado (devolvo o que não vender)</option>
    </select>
</div>
<div>
    <label for="supplier">Fornecedora</label>
    <input id="supplier" name="supplier" value="{{ old('supplier', $p?->supplier) }}" maxlength="120" placeholder="Ex.: Miza">
</div>
@if (! $p || $p->status !== 'archived')
    <div>
        <label for="status">Situação</label>
        <select id="status" name="status">
            <option value="active" @selected(old('status', $p?->status ?? 'active') === 'active')>Ativo</option>
            <option value="draft" @selected(old('status', $p?->status) === 'draft')>Rascunho (não vai para os canais)</option>
        </select>
    </div>
@endif
<div style="grid-column: 1 / -1;">
    <label for="description">Descrição</label>
    <textarea id="description" name="description" rows="5" maxlength="10000">{{ old('description', $p?->description) }}</textarea>
</div>
<div style="grid-column: 1 / -1;"><strong>Dados para envio e nota</strong> <span class="helper">(os marketplaces pedem; dá para completar depois)</span></div>
<div>
    <label for="weight_g">Peso (g)</label>
    <input id="weight_g" name="specs[weight_g]" type="number" min="0" value="{{ old('specs.weight_g', $p?->spec('weight_g')) }}">
</div>
<div>
    <label for="height_cm">Altura (cm)</label>
    <input id="height_cm" name="specs[height_cm]" type="number" step="0.1" min="0" value="{{ old('specs.height_cm', $p?->spec('height_cm')) }}">
</div>
<div>
    <label for="width_cm">Largura (cm)</label>
    <input id="width_cm" name="specs[width_cm]" type="number" step="0.1" min="0" value="{{ old('specs.width_cm', $p?->spec('width_cm')) }}">
</div>
<div>
    <label for="length_cm">Comprimento (cm)</label>
    <input id="length_cm" name="specs[length_cm]" type="number" step="0.1" min="0" value="{{ old('specs.length_cm', $p?->spec('length_cm')) }}">
</div>
<div>
    <label for="ncm">NCM (8 dígitos)</label>
    <input id="ncm" name="specs[ncm]" inputmode="numeric" maxlength="8" value="{{ old('specs.ncm', $p?->spec('ncm')) }}">
</div>
