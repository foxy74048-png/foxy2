{{-- Escolher peças: digite o nome/SKU ou bipe o código de barras (leitor USB ou Bluetooth "digita" o código e dá Enter). --}}
<div class="stack" data-picker-root>
    <div class="form-grid" style="align-items:end;">
        <div style="grid-column: span 2;">
            <label for="pick">Bipe o código de barras ou digite o nome/SKU</label>
            <input id="pick" list="pick-list" autocomplete="off" placeholder="Ex.: 9000026440805 ou Calcinha Cintura Alta Preto M" data-pick>
            <datalist id="pick-list">@foreach ($pickable as $p)<option value="{{ $p['name'] }} · {{ $p['sku'] }}">{{ $p['left'] }} disponível(is) · {{ $p['price'] }}</option>@endforeach</datalist>
        </div>
        <div style="max-width:110px;"><label for="pick-qty">Qtd.</label><input id="pick-qty" type="number" min="1" value="1" data-pick-qty></div>
        <div><button type="button" class="secondary" data-pick-add>Pôr na maleta</button></div>
    </div>
    <div class="helper" data-pick-msg aria-live="polite">{{ $pickable->isEmpty() ? 'Não há peças disponíveis no estoque.' : 'Bipar a mesma peça de novo soma mais uma.' }}</div>
    <div class="table-wrap"><table>
        <thead><tr><th>Peça</th><th style="text-align:right;">Preço</th><th style="width:110px;">Qtd.</th><th style="text-align:right;">Total</th><th></th></tr></thead>
        <tbody data-pick-rows><tr data-pick-empty><td colspan="5" class="helper">Nenhuma peça ainda.</td></tr></tbody>
        <tfoot><tr><td colspan="3" style="text-align:right;"><strong><span data-pick-count>0</span> peça(s)</strong></td><td style="text-align:right;white-space:nowrap;"><strong data-pick-total>R$ 0,00</strong></td><td></td></tr></tfoot>
    </table></div>
</div>
<script>
(function () {
    const root = document.currentScript.previousElementSibling;
    const items = @json($pickable);
    const input = root.querySelector('[data-pick]'), qty = root.querySelector('[data-pick-qty]'), msg = root.querySelector('[data-pick-msg]');
    const rows = root.querySelector('[data-pick-rows]'), empty = root.querySelector('[data-pick-empty]');
    const money = (c) => 'R$ ' + (c / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    const norm = (s) => String(s || '').trim().toUpperCase();

    function find(term) {
        const t = norm(term);
        if (!t) return null;
        return items.find((i) => norm(i.gtin) === t || norm(i.sku) === t || norm(i.name + ' · ' + i.sku) === t)
            || (function () { const hits = items.filter((i) => norm(i.name).includes(t)); return hits.length === 1 ? hits[0] : null; })();
    }

    function total() {
        let count = 0, cents = 0;
        rows.querySelectorAll('tr[data-id]').forEach(function (tr) {
            const q = Math.max(0, parseInt(tr.querySelector('input[type=number]').value, 10) || 0);
            count += q; cents += q * (+tr.dataset.cents);
            tr.querySelector('[data-line-total]').textContent = money(q * (+tr.dataset.cents));
        });
        root.querySelector('[data-pick-count]').textContent = count;
        root.querySelector('[data-pick-total]').textContent = money(cents);
        empty.style.display = rows.querySelector('tr[data-id]') ? 'none' : '';
    }

    function add(given, count) {
        const item = given || find(input.value);
        const n = count || Math.max(1, parseInt(qty.value, 10) || 1);
        if (!item) { msg.textContent = input.value.trim() ? 'Não achei "' + input.value.trim() + '" entre as peças disponíveis.' : 'Bipe ou escolha uma peça.'; input.select(); return; }
        let tr = rows.querySelector('tr[data-id="' + item.id + '"]');
        if (!tr) {
            tr = document.createElement('tr'); tr.dataset.id = item.id; tr.dataset.cents = item.cents;
            const name = document.createElement('td'); name.textContent = item.name;
            const small = document.createElement('div'); small.className = 'helper'; small.textContent = (item.gtin || item.sku) + ' · ' + item.left + ' disponível(is)'; name.appendChild(small);
            const price = document.createElement('td'); price.style.textAlign = 'right'; price.textContent = item.price;
            const q = document.createElement('td'); const field = document.createElement('input'); field.type = 'number'; field.min = 0; field.max = item.left; field.value = 0; field.name = 'items[' + item.id + ']'; field.setAttribute('aria-label', 'Quantidade'); field.addEventListener('input', total); q.appendChild(field);
            const sum = document.createElement('td'); sum.style.textAlign = 'right'; sum.dataset.lineTotal = '';
            const del = document.createElement('td'); const x = document.createElement('button'); x.type = 'button'; x.className = 'secondary'; x.textContent = 'Tirar'; x.addEventListener('click', function () { tr.remove(); total(); }); del.appendChild(x);
            tr.append(name, price, q, sum, del); rows.appendChild(tr);
        }
        const field = tr.querySelector('input[type=number]');
        const next = Math.min(item.left, (parseInt(field.value, 10) || 0) + n);
        msg.textContent = next === (parseInt(field.value, 10) || 0) + n ? item.name + ': ' + next + ' na maleta.' : 'Só há ' + item.left + ' de ' + item.name + '.';
        field.value = next; input.value = ''; qty.value = 1; input.focus(); total();
    }

    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); add(); } });
    input.addEventListener('change', function () { if (find(input.value) && input.value.includes(' · ')) add(); });
    root.querySelector('[data-pick-add]').addEventListener('click', function () { add(); });
    // Voltou com erro: as peças escolhidas continuam na lista.
    Object.entries(@json((object) old('items', []))).forEach(function ([id, n]) { const item = items.find((i) => i.id === id); if (item && +n > 0) add(item, +n); });
})();
</script>
