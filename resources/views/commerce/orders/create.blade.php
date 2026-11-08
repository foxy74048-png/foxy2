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

        <div class="card">
            <h3 style="margin-top:0;">Itens</h3>
            <datalist id="order-skus">@foreach ($variants as $v)<option value="{{ $v->sku }}">{{ $v->fullName() }} · {{ \App\Commerce\Catalog\Money::format($v->price_cents) }}</option>@endforeach</datalist>
            <div class="table-wrap"><table>
                <thead><tr><th>SKU (digite o código ou o nome)</th><th style="width:110px;">Qtd.</th><th style="width:160px;">Preço unitário (R$)</th></tr></thead>
                <tbody>
                    @for ($i = 0; $i < 6; $i++)
                        <tr>
                            <td><input name="items[{{ $i }}][sku]" value="{{ old("items.$i.sku") }}" list="order-skus" maxlength="55" aria-label="SKU do item {{ $i + 1 }}"></td>
                            <td><input name="items[{{ $i }}][quantity]" type="number" min="1" value="{{ old("items.$i.quantity", 1) }}" aria-label="Quantidade do item {{ $i + 1 }}"></td>
                            <td><input name="items[{{ $i }}][price]" value="{{ old("items.$i.price") }}" inputmode="decimal" placeholder="preço do catálogo" aria-label="Preço do item {{ $i + 1 }}"></td>
                        </tr>
                    @endfor
                </tbody>
            </table></div>
            <p class="helper">Preço em branco usa o do catálogo. Linhas sem SKU são ignoradas.</p>
        </div>

        <div class="card form-grid">
            <div><label for="discount">Desconto (R$)</label><input id="discount" name="discount" value="{{ old('discount') }}" inputmode="decimal"></div>
            <div><label for="shipping">Frete cobrado (R$)</label><input id="shipping" name="shipping" value="{{ old('shipping') }}" inputmode="decimal"></div>
            <div>
                <label for="payment_method">Forma de pagamento</label>
                <select id="payment_method" name="payment_method"><option value="">—</option>@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $key => $label)@continue($key === 'marketplace')<option value="{{ $key }}" @selected(old('payment_method') === $key)>{{ $label }}</option>@endforeach</select>
            </div>
            <div style="display:flex;align-items:flex-end;">
                <label style="display:flex;gap:8px;align-items:center;font-weight:600;"><input type="checkbox" name="paid" value="1" style="width:auto;" @checked(old('paid', true))> Já está pago</label>
            </div>
            <div style="grid-column: 1 / -1;">
                <label for="notes">Observações</label>
                <textarea id="notes" name="notes" rows="2" maxlength="2000">{{ old('notes') }}</textarea>
                <div class="helper">Pago: o estoque baixa agora. Não pago: as peças ficam reservadas por 48 h até você confirmar o pagamento.</div>
            </div>
        </div>

        <div><button type="submit">Registrar pedido</button> <a class="button secondary" href="{{ route('commerce.orders') }}">Cancelar</a></div>
    </form>
</x-core::layout>
