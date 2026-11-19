<x-core::layout title="Quais colunas são quais" product="Foxy Finanças" :subtitle="$currentWallet->name">
    <x-slot:nav>@include('finance.nav')</x-slot:nav>

    @include('finance.status')

    <div class="card">
        <p style="margin-top:0;"><strong>{{ $upload['file_name'] }}</strong> · {{ $account->name }}</p>
        <p class="helper">{{ $fromSaved ? 'Já vieram preenchidas as colunas que você usou da última vez nesta conta. Confira na prévia e importe.' : 'Mostre onde está a data, a descrição e o valor. Na próxima vez, esta conta já lembra.' }}</p>
        <div class="table-wrap"><table>
            <thead><tr>@for ($c = 0; $c < $columns; $c++)<th>Coluna {{ $c + 1 }}</th>@endfor</tr></thead>
            <tbody>
                @foreach ($preview as $row)
                    <tr>@for ($c = 0; $c < $columns; $c++)<td>{{ \Illuminate\Support\Str::limit($row[$c] ?? '', 40) }}</td>@endfor</tr>
                @endforeach
            </tbody>
        </table></div>
    </div>

    <form class="card form-grid" method="POST" action="{{ route('finance.statements.import') }}" style="margin-top:16px;align-items:end;">
        @csrf
        @php($select = function (string $name, ?int $value, bool $optional) use ($columns) {
            $html = '<select id="m-'.$name.'" name="'.$name.'"'.($optional ? '' : ' required').'>';
            $html .= $optional ? '<option value="">— não tem —</option>' : '';
            for ($c = 0; $c < $columns; $c++) {
                $html .= '<option value="'.$c.'"'.($value === $c ? ' selected' : '').'>Coluna '.($c + 1).'</option>';
            }

            return $html.'</select>';
        })
        <div><label for="m-date">Data</label>{!! $select('date', old('date') !== null ? (int) old('date') : $guess['date'], false) !!}</div>
        <div><label for="m-description">Descrição</label>{!! $select('description', old('description') !== null ? (int) old('description') : $guess['description'], false) !!}</div>
        <div><label for="m-amount">Valor (ou Entrada / Crédito)</label>{!! $select('amount', old('amount') !== null ? (int) old('amount') : $guess['amount'], false) !!}</div>
        <div><label for="m-debit">Saída / Débito (se for separada)</label>{!! $select('debit', $guess['debit'], true) !!}</div>
        <div><label for="m-category">Categoria (planilha antiga)</label>{!! $select('category', $guess['category'], true) !!}</div>
        <div style="grid-column:1/-1;" class="stack">
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="has_header" value="1" @checked($hasHeader) style="width:auto;"> A primeira linha é o título das colunas</label>
            <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="invert_sign" value="1" @checked($invert) style="width:auto;"> Os gastos vêm <b>positivos</b> (comum na fatura do cartão): inverter o sinal</label>
            <p class="helper" style="margin:0;">Linhas de saldo, totais e títulos de dia são pulados sozinhos. O que tem data mas o valor não deu para ler é avisado, não some calado.</p>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit">Importar</button>
        </div>
    </form>
    <form method="POST" action="{{ route('finance.statements.cancel') }}" style="margin-top:8px;">@csrf<button type="submit" class="secondary">Cancelar</button></form>
</x-core::layout>
