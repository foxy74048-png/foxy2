<x-core::layout title="Novo pedido" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.orders') }}">Voltar aos pedidos</a></x-slot:actions>

    @include('commerce.status')

    <form method="POST" action="{{ route('commerce.orders.store') }}" class="stack" id="order-form">
        @csrf
        {{-- Cliente: busca nas cadastradas; o que não achar vira cliente nova, sem sair da tela. --}}
        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Venda</h3><p class="helper" style="margin:4px 0 0;">Pedidos do Mercado Livre, Shopee, Magalu e Shein entram sozinhos quando o canal estiver conectado.</p></div>
            <div>
                <label for="channel">Onde vendeu</label>
                <select id="channel" name="channel">@foreach (\App\Commerce\Orders\Order::MANUAL_CHANNELS as $c)<option value="{{ $c }}" @selected(old('channel') === $c)>{{ \App\Commerce\Orders\Order::CHANNELS[$c] }}</option>@endforeach</select>
            </div>
            <div style="grid-column: span 2;">
                <label for="customer-search">Cliente</label>
                <input id="customer-search" name="customer[name]" value="{{ old('customer.name') }}" list="customers-list" maxlength="255" autocomplete="off" placeholder="Digite o nome ou o telefone (vazio = venda sem cliente)">
                <datalist id="customers-list">@foreach ($customers as $c)<option value="{{ $c['name'] }}{{ $c['phone'] ? ' · '.$c['phone'] : '' }}"></option>@endforeach</datalist>
                <input type="hidden" name="customer_id" id="customer_id" value="{{ old('customer_id', $preselectedCustomer) }}">
                <div id="customer-info" class="helper" style="margin-top:6px;" aria-live="polite"></div>
            </div>
            <div data-new-customer hidden><label for="cphone">WhatsApp da cliente nova</label><input id="cphone" name="customer[phone]" value="{{ old('customer.phone') }}" maxlength="30" placeholder="11 99999-0000"></div>
            <div data-new-customer hidden><label for="cemail">E-mail (opcional)</label><input id="cemail" name="customer[email]" type="email" value="{{ old('customer.email') }}" maxlength="255"></div>
        </div>

        {{-- Itens: escolhe o produto e depois o tamanho/cor do que existe no catálogo, com o estoque de cada um. --}}
        <div class="card">
            <div class="toolbar">
                <h3 style="margin:0;">Itens</h3>
                @if ($canCreateProducts)<button type="button" class="button secondary" id="quick-toggle">+ Produto que não está cadastrado</button>@endif
            </div>
            <p class="helper" style="margin:6px 0 12px;">Comece a digitar o nome do produto (ou o SKU) e escolha na lista; depois o tamanho/cor. <strong>Enter</strong> passa para o próximo campo. Preço em branco usa o do catálogo.</p>

            @if ($canCreateProducts)
                <div id="quick-product" class="card" hidden style="background:var(--surface-alt);margin-bottom:12px;">
                    <h4 style="margin:0 0 8px;">Cadastrar produto e já colocar no pedido</h4>
                    <div class="form-grid" style="align-items:end;">
                        <div><label for="qp-name">Nome</label><input id="qp-name" maxlength="150" placeholder="Ex.: Calcinha Renda Lisa"></div>
                        <div>
                            <label for="qp-category">Categoria</label>
                            <select id="qp-category"><option value="">— sem categoria —</option>@foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->fullName() }}</option>@endforeach<option value="__nova">+ Nova categoria…</option></select>
                        </div>
                        <div id="qp-new-category-box" hidden><label for="qp-new-category">Nome da categoria nova</label><input id="qp-new-category" maxlength="80" placeholder="Ex.: Calcinhas"></div>
                        <div><label for="qp-price">Preço de venda (R$)</label><input id="qp-price" inputmode="decimal" placeholder="49,90"></div>
                        <div><label for="qp-cost">Custo (R$, opcional)</label><input id="qp-cost" inputmode="decimal"></div>
                        <div><label for="qp-sizes">Tamanhos (opcional)</label><input id="qp-sizes" placeholder="P, M, G, GG"></div>
                        <div><label for="qp-colors">Cores (opcional)</label><input id="qp-colors" placeholder="Preto, Nude"></div>
                        <div><label for="qp-stock">Estoque de cada variação</label><input id="qp-stock" type="number" min="0" value="0"><div class="helper">o que você tem agora de cada tamanho/cor</div></div>
                        <div><button type="button" id="qp-save">Cadastrar e colocar no pedido</button></div>
                    </div>
                    <div id="qp-message" class="helper" style="margin-top:8px;" aria-live="polite"></div>
                    <p class="helper" style="margin-bottom:0;">Depois dá para completar fotos, marca e o resto em Produtos.</p>
                </div>
            @endif

            <datalist id="products-list"></datalist>
            <div class="table-wrap"><table id="order-items">
                <thead><tr><th style="min-width:220px;">Produto</th><th style="width:230px;">Tamanho / cor</th><th style="width:60px;">Tem</th><th style="width:100px;">Qtd.</th><th style="width:120px;">Preço (R$)</th><th style="width:110px;text-align:right;">Total</th><th style="width:48px;"></th></tr></thead>
                <tbody></tbody>
            </table></div>
            <template id="row-template">
                <tr data-row>
                    <td><input list="products-list" maxlength="160" placeholder="Nome do produto ou SKU" autocomplete="off" aria-label="Produto" data-product></td>
                    <td><select aria-label="Tamanho ou cor" data-variant><option value="">—</option></select></td>
                    <td data-has class="muted">—</td>
                    <td><input type="number" min="1" value="1" aria-label="Quantidade" data-qty></td>
                    <td><input inputmode="decimal" placeholder="catálogo" aria-label="Preço unitário" data-price></td>
                    <td data-total style="text-align:right;white-space:nowrap;">—</td>
                    <td><button type="button" class="button secondary" data-remove aria-label="Remover item" title="Remover">×</button></td>
                </tr>
            </template>
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
                <select id="payment_method" name="payment_method"><option value="">—</option>@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $key => $label)@continue($key === 'marketplace')<option value="{{ $key }}" @selected(old('payment_method', 'pix') === $key)>{{ $label }}</option>@endforeach</select>
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
            <div data-credit-box style="grid-column:1/-1;" @if (old('payment_method') !== 'crediario') hidden @endif><div class="alert info" style="margin:0;">Crediário: a venda sai do estoque agora e o valor vira parcelas mensais em <b>Financeiro › A receber</b>, no nome da cliente <span id="credit-preview"></span>. O WhatsApp lembra antes de cada vencimento (se ligado).</div></div>
            <div style="grid-column: 1 / -1;">
                <label for="notes">Observações</label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                <div class="helper">Pago: o estoque baixa agora. Não pago: as peças ficam reservadas por 48 h até você confirmar o pagamento.</div>
            </div>
        </div>

        <div><button type="submit">Registrar pedido</button> <a class="button secondary" href="{{ route('commerce.orders') }}">Cancelar</a></div>
    </form>

    <style>#order-items input, #order-items select{width:100%;} #order-items td{vertical-align:middle;} #order-items .short{color:var(--danger-text);font-weight:600;}</style>
    <script>
        (function () {
            const products = @json($products);
            const customers = @json($customers);
            const oldItems = @json(collect(old('items', []))->filter(fn ($i) => filled($i['sku'] ?? null))->values());
            const money = (c) => 'R$ ' + (c / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const cents = (v) => { v = String(v || '').trim().replace(/\./g, '').replace(',', '.'); const n = parseFloat(v); return isNaN(n) ? null : Math.round(n * 100); };
            const body = document.querySelector('#order-items tbody');
            const template = document.getElementById('row-template');
            let next = 0;

            // ---------------- Produtos: nome único na lista ("Nome · Categoria" se repetir) e busca também por SKU.
            const byKey = new Map(), bySku = new Map();
            function indexProduct(p) {
                let key = p.name;
                if (byKey.has(key)) key = p.name + (p.category ? ' · ' + p.category : '') + ' · ' + p.variants[0].sku;
                byKey.set(key, p); p.key = key;
                p.variants.forEach(function (v) { bySku.set(v.sku.toUpperCase(), {product: p, variant: v}); });
                const option = document.createElement('option');
                option.value = key; option.label = (p.category ? p.category + ' · ' : '') + p.variants.length + (p.variants.length === 1 ? ' variação' : ' variações');
                document.getElementById('products-list').appendChild(option);
            }
            products.forEach(indexProduct);

            function fillVariants(row, product, chosenSku) {
                const select = row.querySelector('[data-variant]');
                select.innerHTML = '';
                if (!product) { select.add(new Option('—', '')); return; }
                if (product.variants.length > 1) select.add(new Option('Escolha…', ''));
                product.variants.forEach(function (v) {
                    select.add(new Option(v.label + (v.available > 0 ? ' — tem ' + v.available : ' — sem estoque'), v.sku));
                });
                select.value = chosenSku || (product.variants.length === 1 ? product.variants[0].sku : '');
            }

            function rowVariant(row) {
                const sku = row.querySelector('[data-variant]').value;
                return sku ? bySku.get(sku.toUpperCase()) : null;
            }

            function update() {
                let sum = 0;
                body.querySelectorAll('[data-row]').forEach(function (row) {
                    const found = rowVariant(row), has = row.querySelector('[data-has]'), total = row.querySelector('[data-total]');
                    const qty = parseInt(row.querySelector('[data-qty]').value, 10) || 0;
                    has.classList.remove('short'); has.title = '';
                    if (!found) { has.textContent = '—'; total.textContent = '—'; return; }
                    has.textContent = found.variant.available;
                    if (qty > found.variant.available) { has.classList.add('short'); has.title = 'Mais do que tem: o estoque fica negativo'; }
                    row.querySelector('[data-price]').placeholder = money(found.variant.price).replace('R$ ', '');
                    const line = (cents(row.querySelector('[data-price]').value) ?? found.variant.price) * qty;
                    total.textContent = money(line); sum += line;
                });
                const discount = cents(document.getElementById('discount').value) || 0, shipping = cents(document.getElementById('shipping').value) || 0;
                const final = Math.max(0, sum - Math.min(discount, sum)) + shipping;
                document.getElementById('order-total').textContent = money(final);
                const n = parseInt(document.getElementById('installments').value, 10) || 1;
                document.getElementById('credit-preview').textContent = final > 0 ? '(' + n + 'x de ' + money(Math.floor(final / n)) + ')' : '';
            }

            function addRow(data) {
                const row = template.content.firstElementChild.cloneNode(true);
                const i = next++;
                row.querySelector('[data-variant]').name = 'items[' + i + '][sku]';
                row.querySelector('[data-qty]').name = 'items[' + i + '][quantity]';
                row.querySelector('[data-price]').name = 'items[' + i + '][price]';
                body.appendChild(row);
                if (data && data.sku) {
                    const found = bySku.get(String(data.sku).toUpperCase());
                    if (found) { row.querySelector('[data-product]').value = found.product.key; fillVariants(row, found.product, found.variant.sku); }
                    row.querySelector('[data-qty]').value = data.quantity || 1;
                    row.querySelector('[data-price]').value = data.price || '';
                }
                update();
                return row;
            }

            body.addEventListener('input', function (e) {
                const row = e.target.closest('[data-row]');
                if (row && e.target.matches('[data-product]')) {
                    const text = e.target.value.trim();
                    const bySkuHit = bySku.get(text.toUpperCase());
                    const product = byKey.get(text) || bySkuHit?.product || null;
                    if (bySkuHit) e.target.value = bySkuHit.product.key;
                    fillVariants(row, product, bySkuHit?.variant.sku);
                    if (product && !row.querySelector('[data-variant]').value) row.querySelector('[data-variant]').focus();
                }
                update();
            });
            body.addEventListener('change', update);
            body.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                const row = e.target.closest('[data-row]');
                if (!row) return;
                e.preventDefault();
                const order = ['[data-product]', '[data-variant]', '[data-qty]', '[data-price]'];
                const at = order.findIndex(function (sel) { return e.target.matches(sel); });
                if (at >= 0 && at < order.length - 1) { row.querySelector(order[at + 1]).focus(); return; }
                const after = row.nextElementSibling;
                (after || addRow()).querySelector('[data-product]').focus();
            });
            body.addEventListener('click', function (e) {
                const btn = e.target.closest('[data-remove]');
                if (!btn) return;
                body.querySelectorAll('[data-row]').length > 1 ? btn.closest('[data-row]').remove() : (body.innerHTML = '', addRow());
                update();
            });
            document.getElementById('add-item').addEventListener('click', function () { addRow().querySelector('[data-product]').focus(); });
            ['discount', 'shipping', 'installments'].forEach(function (id) { document.getElementById(id).addEventListener('input', update); });
            (oldItems.length ? oldItems : [null]).forEach(addRow);

            // ---------------- Cliente: escolhe da lista (mostra telefone, última compra e se deve algo) ou vira cliente nova.
            const search = document.getElementById('customer-search'), hidden = document.getElementById('customer_id'), info = document.getElementById('customer-info');
            const byLabel = new Map(customers.map(function (c) { return [c.name + (c.phone ? ' · ' + c.phone : ''), c]; }));
            function syncCustomer() {
                const text = search.value.trim();
                const found = byLabel.get(text) || customers.find(function (c) { return c.id === hidden.value && text === ''; });
                document.querySelectorAll('[data-new-customer]').forEach(function (el) { el.hidden = !!found || text === ''; });
                if (found) {
                    hidden.value = found.id;
                    if (text === '') search.value = found.name + (found.phone ? ' · ' + found.phone : '');
                    info.innerHTML = '';
                    const parts = [found.phone ? 'WhatsApp ' + found.phone : 'sem WhatsApp cadastrado', found.last ? 'última compra ' + found.last : 'primeira compra'];
                    info.textContent = '✔ Cliente cadastrada · ' + parts.join(' · ');
                    if (found.open > 0) { const b = document.createElement('strong'); b.style.color = 'var(--danger-text)'; b.textContent = ' · deve ' + money(found.open) + ' em aberto'; info.appendChild(b); }
                } else {
                    hidden.value = '';
                    info.textContent = text === '' ? 'Venda sem cliente (balcão). Para crediário, a cliente é obrigatória.' : 'Cliente nova: vamos cadastrar "' + text + '" junto com o pedido.';
                }
            }
            search.addEventListener('input', syncCustomer);
            syncCustomer();

            // ---------------- Crediário e "já pago".
            const method = document.getElementById('payment_method');
            const credit = function () { const on = method.value === 'crediario'; document.querySelectorAll('[data-credit-box]').forEach(function (b) { b.hidden = !on; }); document.querySelector('[data-paid-box]').style.display = on ? 'none' : 'flex'; update(); };
            method.addEventListener('change', credit);
            credit();

            // ---------------- Produto novo sem sair do pedido.
            const toggle = document.getElementById('quick-toggle');
            if (toggle) {
                const panel = document.getElementById('quick-product'), message = document.getElementById('qp-message');
                const category = document.getElementById('qp-category');
                toggle.addEventListener('click', function () { panel.hidden = !panel.hidden; if (!panel.hidden) document.getElementById('qp-name').focus(); });
                category.addEventListener('change', function () { document.getElementById('qp-new-category-box').hidden = category.value !== '__nova'; });
                document.getElementById('qp-save').addEventListener('click', async function () {
                    const button = this; button.disabled = true; message.textContent = 'Cadastrando…';
                    const payload = {
                        name: document.getElementById('qp-name').value, price: document.getElementById('qp-price').value, cost: document.getElementById('qp-cost').value,
                        sizes: document.getElementById('qp-sizes').value, colors: document.getElementById('qp-colors').value, stock: document.getElementById('qp-stock').value || 0,
                        category_id: category.value && category.value !== '__nova' ? category.value : null,
                        new_category: category.value === '__nova' ? document.getElementById('qp-new-category').value : null,
                    };
                    try {
                        const res = await fetch(@json(route('commerce.products.quick')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) }, body: JSON.stringify(payload) });
                        const data = await res.json();
                        if (!res.ok) { message.textContent = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Não deu para cadastrar.'); button.disabled = false; return; }
                        indexProduct(data.product);
                        if (payload.new_category && data.product.category && ![...category.options].some(function (o) { return o.text === data.product.category; })) {
                            category.add(new Option(data.product.category, ''), category.options.length - 1);
                        }
                        let row = [...body.querySelectorAll('[data-row]')].find(function (r) { return !r.querySelector('[data-variant]').value; }) || addRow();
                        row.querySelector('[data-product]').value = data.product.key;
                        fillVariants(row, data.product);
                        update();
                        message.textContent = '✔ ' + data.product.name + ' cadastrado com ' + data.product.variants.length + ' variação(ões) e colocado no pedido.';
                        ['qp-name', 'qp-price', 'qp-cost', 'qp-sizes', 'qp-colors', 'qp-new-category'].forEach(function (id) { document.getElementById(id).value = ''; });
                        (row.querySelector('[data-variant]').value ? row.querySelector('[data-qty]') : row.querySelector('[data-variant]')).focus();
                    } catch (e) { message.textContent = 'Sem conexão com o sistema. Tente de novo.'; }
                    button.disabled = false;
                });
            }
        })();
    </script>
</x-core::layout>
