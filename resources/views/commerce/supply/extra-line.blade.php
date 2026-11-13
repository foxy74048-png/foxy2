{{-- Linha acrescentada à mão na conferência da remessa (não veio na leitura do documento). --}}
@php($key = trim(str_replace(['][', '[', ']'], '.', $prefix), '.'))
<tr data-extra>
    <td class="helper"><span class="badge">acrescentada</span> <button type="button" class="button secondary" data-remove="tr" aria-label="Remover linha" title="Remover" style="margin-left:6px;padding:2px 10px;">×</button></td>
    <td><input name="{{ $prefix }}[color]" value="{{ $line['color'] ?? '' }}" maxlength="40" aria-label="Cor"></td>
    <td><input name="{{ $prefix }}[size]" value="{{ $line['size'] ?? '' }}" maxlength="20" aria-label="Tamanho"></td>
    <td><input name="{{ $prefix }}[barcode]" value="{{ $line['barcode'] ?? '' }}" maxlength="14" inputmode="numeric" aria-label="Código de barras" placeholder="opcional"></td>
    <td><input name="{{ $prefix }}[quantity]" type="number" min="0" value="{{ $line['quantity'] ?? 1 }}" aria-label="Quantidade">@error($key.'.quantity')<div class="helper" style="color:var(--danger-text)">{{ $message }}</div>@enderror</td>
</tr>
