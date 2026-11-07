{{-- Linhas de peças do kit (SKU + quantidade). $rows = [[sku, quantity], ...]; $pieces = variantes sugeridas. --}}
<datalist id="kit-pieces">
    @foreach ($pieces as $v)<option value="{{ $v->sku }}">{{ $v->fullName() }}</option>@endforeach
</datalist>
<div class="table-wrap"><table>
    <thead><tr><th>SKU da peça</th><th style="width:120px;">Quantidade</th></tr></thead>
    <tbody>
        @foreach ($rows as $i => $row)
            <tr>
                <td><input name="components[{{ $i }}][sku]" value="{{ $row[0] }}" list="kit-pieces" maxlength="55" aria-label="SKU da peça {{ $i + 1 }}"></td>
                <td><input name="components[{{ $i }}][quantity]" type="number" min="1" max="999" value="{{ $row[1] }}" aria-label="Quantidade da peça {{ $i + 1 }}"></td>
            </tr>
        @endforeach
    </tbody>
</table></div>
<p class="helper">Comece a digitar o SKU ou o nome: a lista mostra as variantes da loja. Linhas vazias são ignoradas. O estoque do kit é calculado pelas peças.</p>
