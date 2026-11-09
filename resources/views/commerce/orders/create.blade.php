<x-core::layout title="Novo pedido" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    <form method="POST" action="{{ route('commerce.orders.store') }}" class="stack">
        @csrf
        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Venda</h3><p class="helper" style="margin:4px 0 0;">Pedidos do Mercado Livre, Shopee, Magalu e Shein entram sozinhos quando o canal estiver conectado.</p></div>
            <div>
                <label for="channel">Onde vendeu</label>
                <select id="channel" name="channel">@foreach (\App\Commerce\Orders\Order::MANUAL_CHANNELS as $c)<option value="{{ $c }}" @selected(old('channel') === $c)>{{ \App\Commerce\Orders\Order::CHANNELS[$c] }}</option>@endforeach</select>
            </div>
            <div>
                <label for="customer_id">Cliente já cadastrado</label>
                <select id="customer_id" name="customer_id">
                    <option value="">— novo ou sem cadastro —</option>
                    @foreach ($customers as $c)<option value="{{ $c->id }}" @selected(old('customer_id') === $c->id)>{{ $c->name }}{{ $c->phone ? ' · '.$c->phone : '' }}</option>@endforeach
                </select>
            </div>
            <div><label for="cname">Ou cliente novo: nome</label><input id="cname" name="customer[name]" value="{{ old('customer.name') }}" maxlength="255"></div>
            <div><label for="cphone">WhatsApp</label><input id="cphone" name="customer[phone]" value="{{ old('customer.phone') }}" maxlength="30"></div>
            <div><label for="cemail">E-mail</label><input id="cemail" name="customer[email]" type="email" value="{{ old('customer.email') }}" maxlength="255"></div>
        </div>

        @php($oldItems = collect(old('items', []))->filter(fn ($i) => filled($i['sku'] ?? null))->values())
        <div class="card">
            <h3 style="margin-top:0;">Itens</h3>
            <p class="helper" style="margin-top:0;">Digite o SKU ou comece pelo nome e escolha na lista. <strong>Enter</strong> passa para a quantidade e depois abre a próxima linha. Preço em branco usa o do catálogo.</p>
            <datalist id="order-skus">@foreach ($variants as $v)<option value="{{ $v->sku }}">{{ $v->fullName() }} · {{ \App\Commerce\Catalog\Money::format($v->price_cents) }}</option>@endforeach</datalist>
            <div class="table-wrap"><table id="order-items">
                <thead><tr><th style="width:210px;">SKU</th><th>Produto</th><th style="width:90px;">Tem</th><th style="width:90px;">Qtd.</th><th style="width:140px;">Preço (R$)</th><th style="width:110px;text-align:right;">Total</th><th style="width:48px;"></th></tr></thead>
                <tbody>
                    @foreach ($oldItems->isEmpty() ? [['sku' => '', 'quantity' => 1, 'price' => '']] : $oldItems as $i => $item)
                        <tr data-row>
                            <td><input name="items[{{ $i }}][sku]" value="{{ $item['sku'] ?? '' }}" list="order-skus" maxlength="55" placeholder="SKU ou nome" autocomplete="off" aria-label="SKU" data-sku @if ($loop->first && $oldItems->isEmpty()) autofocus @endif></td>
                            <td data-name class="muted">—</td>
                            <td data-has class="muted">—</td>
                            <td><input name="items[{{ $i }}][quantity]" type="number" min="1" value="{{ $item['quantity'] ?? 1 }}" aria-label="Quantidade" data-qty></td>
                            <td><input name="items[{{ $i }}][price]" value="{{ $item['price'] ?? '' }}" inputmode="decimal" placeholder="catálogo" aria-label="Preço unitário" data-price></td>
                            <td data-total style="text-align:right;white-space:nowrap;">—</td>
                            <td><button type="button" class="button secondary" data-remove aria-label="Remover item" title="Remover">×</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:12px;">
                <button type="button" class="button secondary" id="add-item">+ Adicionar item</button>
                <span style="margin-left:auto;" aria-live="polite"><span class="muted">Total do pedido:</span> <strong id="order-total" style="font-size:1.15rem;">R$ 0,00</strong></span>
            </div>
        </div>
        <div class="card form-grid">
            <div><label for="discount">Desconto (R$)</label><input id="discount" name="discount" value="{{ old('discount') }}" inputmode="decimal"></div>
            <div><label for="shipping">Frete cobrado (R$)</label><input id="shipping" name="shipping" value="{{ old('shipping') }}" inputmode="decimal"></div>
            <div>
                <label for="payment_method">Forma de pagamento</label>
                <select id="payment_method" name="payment_method"><option value="">—</option>@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $key => $label)@continue($key === 'marketplace')<option value="{{ $key }}" @selected(old('payment_method') === $key)>{{ $label }}</option>@endforeach</select>
            </div>
            <div style="display:flex;align-items:flex-end;" data-paid-box>
                <label style="display:flex;gap:8px;align-items:center;font-weight:600;"><input type="checkbox" name="paid" value="1" style="width:auto;" @checked(old('paid', true))> Já está pago</label>
            </div>
            <div data-credit-box @if (old('payment_method') !== 'crediario') hidden @endif>
                <label for="installments">Parcelas</label>
                <input id="installments" name="installments" type="number" min="1" max="{{ \App\Commerce\Finance\FinanceService::MAX_INSTALLMENTS }}" value="{{ old('installments', 2) }}">
            </div>
            <div data-credit-box @if (old('payment_method') !== 'crediario') hidden @endif>
                <label for="first_due">1º vencimento</label>
                <input id="first_due" name="first_due" type="date" value="{{ old('first_due', today()->addMonth()->toDateString()) }}">
            </div>
            <div data-credit-box style="grid-column:1/-1;" @if (old('payment_method') !== 'crediario') hidden @endif><div class="alert info" style="margin:0;">Crediário: a venda sai do estoque agora e o valor vira parcelas mensais em <b>Financeiro › A receber</b>, no nome da cliente. O WhatsApp lembra antes de cada vencimento (se ligado).</div></div>
            <div style="grid-column: 1 / -1;">
                <label for="notes">Observações</label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                <div class="helper">Pago: o estoque baixa agora. Não pago: as peças ficam reservadas por 48 h até você confirmar o pagamento.</div>
            </div>
        </div>

        <div><button type="submit">Registrar pedido</button> <a class="button secondary" href="{{ route('commerce.orders') }}">Cancelar</a></div>
    </form>
    <style>#order-items input{width:100%;} #order-items td{vertical-align:middle;} #order-items .unknown{color:var(--danger-text);} #order-items .short{color:var(--danger-text);font-weight:600;}</style>
    <script>
        (function () {
            const catalog = @json($catalog);
            const body = document.querySelector('#order-items tbody');
            const money = (c) => 'R$ ' + (c / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const cents = (v) => { v = String(v || '').trim().replace(/\./g, '').replace(',', '.'); const n = parseFloat(v); return isNaN(n) ? null : Math.round(n * 100); };
            let next = body.querySelectorAll('[data-row]').length;

            function fill(row) {
                const item = catalog[row.querySelector('[data-sku]').value.trim().toUpperCase()];
                const name = row.querySelector('[data-name]'), has = row.querySelector('[data-has]'), total = row.querySelector('[data-total]');
                const qty = parseInt(row.querySelector('[data-qty]').value, 10) || 0;
                name.classList.remove('unknown'); has.classList.remove('short'); has.title = '';
                if (!row.querySelector('[data-sku]').value.trim()) { name.textContent = '—'; has.textContent = '—'; total.textContent = '—'; return 0; }
                if (!item) { name.textContent = 'SKU não encontrado nesta loja'; name.classList.add('unknown'); has.textContent = '—'; total.textContent = '—'; return 0; }
                name.textContent = item.name;
                has.textContent = item.available;
                if (qty > item.available) { has.classList.add('short'); has.title = 'Mais do que tem: o estoque fica negativo'; }
                const unit = cents(row.querySelector('[data-price]').value) ?? item.price;
                row.querySelector('[data-price]').placeholder = money(item.price).replace('R$ ', '');
                total.textContent = money(unit * qty);
                return unit * qty;
            }
            function summary() {
                let sum = 0;
                body.querySelectorAll('[data-row]').forEach(function (r) { sum += fill(r); });
                const discount = cents(document.getElementById('discount').value) || 0, shipping = cents(document.getElementById('shipping').value) || 0;
                document.getElementById('order-total').textContent = money(Math.max(0, sum - Math.min(discount, sum)) + shipping);
            }
            function addRow() {
                const row = body.querySelector('[data-row]').cloneNode(true);
                row.querySelectorAll('input').forEach(function (input) {
                    input.name = input.name.replace(/items\[\d+\]/, 'items[' + next + ']');
                    input.value = input.matches('[data-qty]') ? '1' : '';
                    input.removeAttribute('autofocus');
                });
                next++;
                body.appendChild(row);
                summary();
                row.querySelector('[data-sku]').focus();
            }
            body.addEventListener('input', summary);
            document.getElementById('discount').addEventListener('input', summary);
            document.getElementById('shipping').addEventListener('input', summary);
            body.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                const row = e.target.closest('[data-row]');
                if (!row) return;
                e.preventDefault();
                if (e.target.matches('[data-sku]')) { row.querySelector('[data-qty]').select(); return; }
                const after = row.nextElementSibling;
                after ? after.querySelector('[data-sku]').focus() : addRow();
            });
            body.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-remove]');
                if (!btn) return;
                const row = btn.closest('[data-row]');
                if (body.querySelectorAll('[data-row]').length > 1) { row.remove(); } else { row.querySelectorAll('input').forEach(function (i) { i.value = i.matches('[data-qty]') ? '1' : ''; }); }
                summary();
            });
            document.getElementById('add-item').addEventListener('click', addRow);
            const method = document.getElementById('payment_method');
            const credit = function () { const on = method.value === 'crediario'; document.querySelectorAll('[data-credit-box]').forEach(function (b) { b.hidden = !on; }); document.querySelector('[data-paid-box]').hidden = on; };
            method.addEventListener('change', credit);
            credit();
            summary();
        })();
    </script>
</x-core::layout>
