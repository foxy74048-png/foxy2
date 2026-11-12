<x-core::layout title="Estoque" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')
    @include('commerce.stock._tabs', ['tab' => 'entrada'])

    @php($oldItems = collect(old('items', []))->filter(fn ($i) => filled($i['sku'] ?? null) || filled($i['quantity'] ?? null))->values())

    <form class="card" method="POST" action="{{ route('commerce.stock.receive') }}" id="receive-form">
        @csrf
        <h3 style="margin-top:0;">Entrada de mercadoria</h3>
        <p class="helper" style="margin-top:0;">Digite o SKU (ou comece pelo nome e escolha na lista) e a quantidade. <strong>Enter</strong> passa para o próximo item. Muitas peças de uma vez? <a href="{{ route('commerce.imports') }}#produtos">Entrada em lote por planilha ou PDF</a> · chegou de fornecedora com comprovante ou nota? <a href="{{ route('commerce.supply') }}#importar">Remessa de fornecedor</a>.</p>

        <datalist id="stock-skus">@foreach ($catalog as $sku => $item)<option value="{{ $sku }}">{{ $item['name'] }}</option>@endforeach</datalist>

        <div class="table-wrap"><table id="receive-items">
            <thead><tr><th style="width:220px;">SKU</th><th>Produto</th><th style="width:110px;">Tem hoje</th><th style="width:120px;">Chegou</th><th style="width:48px;"><span class="sr-only">Remover</span></th></tr></thead>
            <tbody>
                @forelse ($oldItems as $i => $item)
                    <tr data-row>
                        <td><input name="items[{{ $i }}][sku]" value="{{ $item['sku'] ?? '' }}" list="stock-skus" maxlength="55" placeholder="SKU" aria-label="SKU" autocomplete="off" data-sku></td>
                        <td data-name class="muted">—</td>
                        <td data-has class="muted">—</td>
                        <td><input name="items[{{ $i }}][quantity]" value="{{ $item['quantity'] ?? '' }}" type="number" min="1" max="1000000" placeholder="Qtd." aria-label="Quantidade" data-qty></td>
                        <td><button type="button" class="button secondary" data-remove aria-label="Remover item" title="Remover">×</button></td>
                    </tr>
                @empty
                    <tr data-row>
                        <td><input name="items[0][sku]" list="stock-skus" maxlength="55" placeholder="SKU" aria-label="SKU" autocomplete="off" data-sku autofocus></td>
                        <td data-name class="muted">—</td>
                        <td data-has class="muted">—</td>
                        <td><input name="items[0][quantity]" type="number" min="1" max="1000000" placeholder="Qtd." aria-label="Quantidade" data-qty></td>
                        <td><button type="button" class="button secondary" data-remove aria-label="Remover item" title="Remover">×</button></td>
                    </tr>
                @endforelse
            </tbody>
        </table></div>

        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px;">
            <button type="button" class="button secondary" id="add-item">+ Adicionar item</button>
            <span class="muted" id="receive-total" aria-live="polite"></span>
        </div>

        <div style="display:flex;gap:8px;margin-top:16px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--border, #e5e7eb);padding-top:16px;">
            <input name="reason" value="{{ old('reason') }}" maxlength="200" placeholder="Observação (ex.: NF 1234 do fornecedor)" style="max-width:360px;" aria-label="Observação">
            <button type="submit">Registrar entrada</button>
        </div>
    </form>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Últimas entradas</h3>
        @if ($recent->isEmpty())
            <div class="empty">Nenhuma entrada registrada ainda.</div>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Quando</th><th>SKU</th><th>Produto</th><th>Qtd.</th><th>Ficou com</th><th>Observação</th><th>Por</th></tr></thead>
                <tbody>
                    @foreach ($recent as $m)
                        <tr>
                            <td>{{ $m->created_at->timezone('America/Sao_Paulo')->format('d/m H:i') }}</td>
                            <td>@if ($m->variant)<a href="{{ route('commerce.stock.show', $m->variant) }}"><strong>{{ $m->variant->sku }}</strong></a>@endif</td>
                            <td>{{ $m->variant?->fullName() }}</td>
                            <td>+{{ $m->quantity }}</td>
                            <td>{{ $m->on_hand_after }}</td>
                            <td>{{ $m->reason ?: '—' }}</td>
                            <td>{{ $m->user?->name ?? 'Sistema' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        @endif
    </div>

    <style>.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);} #receive-items input{width:100%;} #receive-items td{vertical-align:middle;} #receive-items .unknown{color:var(--danger-text);}</style>
    <script>
        (function () {
            const catalog = @json($catalog);
            const body = document.querySelector('#receive-items tbody');
            const total = document.getElementById('receive-total');
            let next = body.querySelectorAll('[data-row]').length;

            function fill(row) {
                const sku = row.querySelector('[data-sku]').value.trim().toUpperCase();
                const item = catalog[sku];
                const name = row.querySelector('[data-name]');
                const has = row.querySelector('[data-has]');
                name.classList.remove('unknown');
                if (!sku) { name.textContent = '—'; has.textContent = '—'; return; }
                if (!item) { name.textContent = 'SKU não encontrado nesta loja'; name.classList.add('unknown'); has.textContent = '—'; return; }
                name.textContent = item.name;
                const qty = parseInt(row.querySelector('[data-qty]').value, 10) || 0;
                has.textContent = qty > 0 ? item.on_hand + ' → ' + (item.on_hand + qty) : String(item.on_hand);
            }

            function summary() {
                let items = 0, units = 0;
                body.querySelectorAll('[data-row]').forEach(function (row) {
                    const q = parseInt(row.querySelector('[data-qty]').value, 10) || 0;
                    if (catalog[row.querySelector('[data-sku]').value.trim().toUpperCase()] && q > 0) { items++; units += q; }
                });
                total.textContent = items ? items + (items === 1 ? ' item, ' : ' itens, ') + units + (units === 1 ? ' unidade' : ' unidades') : '';
            }

            function addRow(focus) {
                const row = body.querySelector('[data-row]').cloneNode(true);
                row.querySelector('[data-sku]').name = 'items[' + next + '][sku]';
                row.querySelector('[data-qty]').name = 'items[' + next + '][quantity]';
                row.querySelector('[data-sku]').value = '';
                row.querySelector('[data-qty]').value = '';
                row.querySelector('[data-sku]').removeAttribute('autofocus');
                next++;
                body.appendChild(row);
                fill(row);
                if (focus) row.querySelector('[data-sku]').focus();
                return row;
            }

            body.addEventListener('input', function (e) {
                const row = e.target.closest('[data-row]');
                if (row) { fill(row); summary(); }
            });

            body.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                const row = e.target.closest('[data-row]');
                if (!row) return;
                e.preventDefault();
                if (e.target.matches('[data-sku]')) { row.querySelector('[data-qty]').focus(); return; }
                const after = row.nextElementSibling;
                after ? after.querySelector('[data-sku]').focus() : addRow(true);
            });

            body.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-remove]');
                if (!btn) return;
                const row = btn.closest('[data-row]');
                if (body.querySelectorAll('[data-row]').length > 1) { row.remove(); }
                else { row.querySelector('[data-sku]').value = ''; row.querySelector('[data-qty]').value = ''; fill(row); }
                summary();
            });

            document.getElementById('add-item').addEventListener('click', function () { addRow(true); summary(); });
            body.querySelectorAll('[data-row]').forEach(fill);
            summary();
        })();
    </script>
</x-core::layout>
