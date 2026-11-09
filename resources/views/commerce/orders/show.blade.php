<x-core::layout :title="'Pedido #'.$order->number" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.orders') }}">Voltar aos pedidos</a></x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @include('commerce.status')

    <div class="grid cards">
        <div class="card"><div class="muted">Situação</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $order->statusLabel() }}</h2><div class="helper">{{ $order->channelLabel() }}{{ $order->external_id ? ' · '.$order->external_id : '' }}</div></div>
        <div class="card"><div class="muted">Total</div><h2 style="margin:4px 0;">{{ $money($order->total_cents) }}</h2><div class="helper">{{ \App\Commerce\Orders\Order::PAYMENT_METHODS[$order->payment_method] ?? 'pagamento não informado' }}</div></div>
        <div class="card"><div class="muted">Lucro bruto</div><h2 style="margin:4px 0;">{{ $order->status === 'canceled' ? '—' : $money($order->profitCents()) }}</h2><div class="helper">taxas {{ $money($order->fee_cents) }} · custo {{ $money($order->cost_cents) }}</div></div>
        <div class="card"><div class="muted">Cliente</div><h2 style="margin:4px 0;font-size:1.1rem;">@if ($order->customer)<a href="{{ route('commerce.customers.show', $order->customer) }}">{{ $order->customer->name }}</a>@else — @endif</h2><div class="helper">{{ $order->customer?->phone }}</div></div>
    </div>

    {{-- Próximo passo, sempre à vista --}}
    @if ($order->status !== 'canceled' && $order->status !== 'completed')
        <div class="card" style="margin-top:16px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
            @if ($order->status === 'awaiting_payment')
                <form method="POST" action="{{ route('commerce.orders.pay', $order) }}" style="display:flex;gap:8px;align-items:flex-end;">
                    @csrf
                    <div><label for="pm">Recebido por</label><select id="pm" name="payment_method">@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $k => $l)@continue($k === 'marketplace')<option value="{{ $k }}" @selected($order->payment_method === $k)>{{ $l }}</option>@endforeach</select></div>
                    <button type="submit">Confirmar pagamento</button>
                </form>
                @if ($order->reserved_until)<span class="helper">Peças reservadas até {{ $order->reserved_until->format('d/m H:i') }}.</span>@endif
            @else
                @if ($order->status === 'paid')
                    <form method="POST" action="{{ route('commerce.orders.advance', $order) }}">@csrf<input type="hidden" name="status" value="in_fulfillment"><button type="submit" class="secondary">Separando</button></form>
                @endif
                @if (in_array($order->status, ['paid', 'in_fulfillment'], true))
                    <form method="POST" action="{{ route('commerce.orders.advance', $order) }}" style="display:flex;gap:8px;align-items:flex-end;">
                        @csrf<input type="hidden" name="status" value="shipped">
                        <div><label for="tracking">Código de rastreio (opcional)</label><input id="tracking" name="tracking_code" maxlength="60"></div>
                        <button type="submit" class="secondary">Enviado</button>
                    </form>
                @endif
                <form method="POST" action="{{ route('commerce.orders.advance', $order) }}">@csrf<input type="hidden" name="status" value="completed"><button type="submit">Concluído (entregue)</button></form>
            @endif
            @if (\App\Commerce\Access::allows($currentMembership, 'commerce.orders.cancel'))
                <form method="POST" action="{{ route('commerce.orders.cancel', $order) }}" style="display:flex;gap:8px;align-items:flex-end;margin-left:auto;" onsubmit="return confirm('Cancelar este pedido? As peças voltam ao estoque.');">
                    @csrf
                    <div><label for="reason">Motivo do cancelamento</label><input id="reason" name="reason" maxlength="300"></div>
                    <button type="submit" class="danger">Cancelar pedido</button>
                </form>
            @endif
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Itens</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th>SKU</th><th style="text-align:right;">Qtd.</th><th style="text-align:right;">Preço</th><th style="text-align:right;">Total</th></tr></thead>
            <tbody>
                @foreach ($order->items as $i)<tr><td>{{ $i->name }}</td><td>{{ $i->sku }}</td><td style="text-align:right;">{{ $i->quantity }}</td><td style="text-align:right;">{{ $money($i->unit_price_cents) }}</td><td style="text-align:right;">{{ $money($i->total_cents) }}</td></tr>@endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="4" style="text-align:right;">Subtotal</td><td style="text-align:right;">{{ $money($order->subtotal_cents) }}</td></tr>
                @if ($order->discount_cents)<tr><td colspan="4" style="text-align:right;">Desconto</td><td style="text-align:right;">− {{ $money($order->discount_cents) }}</td></tr>@endif
                @if ($order->shipping_cents)<tr><td colspan="4" style="text-align:right;">Frete</td><td style="text-align:right;">{{ $money($order->shipping_cents) }}</td></tr>@endif
                <tr><td colspan="4" style="text-align:right;"><strong>Total</strong></td><td style="text-align:right;"><strong>{{ $money($order->total_cents) }}</strong></td></tr>
            </tfoot>
        </table></div>
    </div>

    @if ($order->receivables->isNotEmpty())
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Parcelas a receber <span class="muted">({{ $order->receivables->where('status', 'aberto')->count() }} em aberto)</span></h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Parcela</th><th>Vencimento</th><th style="text-align:right;">Valor</th><th>Situação</th></tr></thead>
                <tbody>
                    @foreach ($order->receivables as $r)
                        <tr><td>{{ $r->installmentLabel() ?: 'Única' }}</td><td>{{ $r->due_date->format('d/m/Y') }}</td><td style="text-align:right;">{{ $money($r->amount_cents) }}</td><td><span @class(['badge', 'success' => $r->status === 'recebido', 'danger' => $r->isOverdue()])>{{ $r->statusLabel() }}</span></td></tr>
                    @endforeach
                </tbody>
            </table></div>
            @if (\App\Commerce\Access::allows($currentMembership, 'commerce.finance.manage'))<p style="margin-bottom:0;"><a href="{{ route('commerce.finance', ['busca' => '#'.$order->number]) }}">Receber ou cobrar em Financeiro › A receber</a></p>@endif
        </div>
    @endif

    @if ($order->status !== 'canceled' && $order->items->whereNotNull('variant_id')->isNotEmpty())
        @php($manualOrder = in_array($order->channel, \App\Commerce\Orders\Order::MANUAL_CHANNELS, true))
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Trocar peça</h3>
            <p class="helper" style="margin-top:0;">A cliente quer outro tamanho ou cor? A peça devolvida volta ao estoque e a nova sai, sem cancelar o pedido.{{ $order->status === 'awaiting_payment' ? ' Como o pedido ainda não foi pago, só a reserva é trocada.' : '' }}</p>
            <datalist id="swap-skus">@foreach ($swap as $v)<option value="{{ $v->sku }}">{{ $v->fullName() }}</option>@endforeach</datalist>
            <form method="POST" action="{{ route('commerce.orders.exchange', $order) }}" class="form-grid" style="align-items:end;">
                @csrf
                <div>
                    <label for="x-item">Peça que volta</label>
                    <select id="x-item" name="item_id">@foreach ($order->items->whereNotNull('variant_id') as $i)<option value="{{ $i->id }}" @selected(old('item_id') === $i->id)>{{ $i->quantity }}× {{ $i->name }}</option>@endforeach</select>
                </div>
                <div>
                    <label for="x-sku">Peça nova (SKU)</label>
                    <input id="x-sku" name="sku" list="swap-skus" maxlength="55" value="{{ old('sku') }}" placeholder="Digite o SKU ou o nome" autocomplete="off" required>
                </div>
                <div>
                    <label for="x-qty">Quantidade</label>
                    <input id="x-qty" name="quantity" type="number" min="1" value="{{ old('quantity', 1) }}" required>
                </div>
                @if ($manualOrder)
                    <div>
                        <label for="x-price">Preço da nova (R$, opcional)</label>
                        <input id="x-price" name="price" inputmode="decimal" value="{{ old('price') }}" placeholder="Igual ao da peça trocada">
                    </div>
                @endif
                <div><button type="submit">Registrar troca</button></div>
            </form>
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Nota fiscal</h3>
        @forelse ($order->fiscalDocuments as $doc)
            <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
                <div>
                    <strong>NF-e {{ $doc->number ?: "—" }}</strong> <span class="muted">série {{ $doc->series ?: "—" }}{{ $doc->issued_at ? " · emitida em ".$doc->issued_at->format("d/m/Y") : "" }}{{ $doc->source !== "manual" ? " · veio do ".(config("commerce.channels.{$doc->source}.label") ?? $doc->source) : "" }}</span>
                    <div class="helper" style="font-family:monospace;">{{ $doc->formattedKey() }}</div>
                </div>
                <div style="display:flex;gap:8px;">
                    @if ($doc->xml_file_id)<a class="button secondary" href="{{ route("commerce.orders.fiscal.xml", [$order, $doc->id]) }}">Baixar XML</a>@endif
                    <form method="POST" action="{{ route("commerce.orders.fiscal.destroy", [$order, $doc->id]) }}" onsubmit="return confirm('Desligar esta nota do pedido?');">@csrf<button type="submit" class="secondary">Desligar</button></form>
                </div>
            </div>
        @empty
            @if (in_array($order->status, \App\Commerce\Orders\Order::SOLD, true))
                <p class="helper" style="margin-top:0;">Emitiu a nota no seu emissor ou no marketplace? Anexe o XML ou cole a chave de acesso (44 números do DANFE).</p>
                <form method="POST" action="{{ route("commerce.orders.fiscal", $order) }}" enctype="multipart/form-data" class="form-grid" style="align-items:end;">
                    @csrf
                    <div><label for="xml">XML da nota</label><input id="xml" type="file" name="xml" accept=".xml,text/xml"></div>
                    <div><label for="access_key">ou a chave de acesso</label><input id="access_key" name="access_key" inputmode="numeric" maxlength="60" placeholder="0000 0000 0000 ..."></div>
                    <div><button type="submit">Ligar nota</button></div>
                </form>
            @else
                <p class="helper" style="margin:0;">A nota entra quando o pedido estiver pago.</p>
            @endif
        @endforelse
    </div>
    @if ($order->status !== 'canceled')
        @php($manual = in_array($order->channel, \App\Commerce\Orders\Order::MANUAL_CHANNELS, true))
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Editar dados do pedido</h3>
            <form method="POST" action="{{ route('commerce.orders.update', $order) }}" class="form-grid" style="align-items:end;">
                @csrf
                @if ($order->payment_method !== 'marketplace')
                    <div><label for="e-pm">Forma de pagamento</label><select id="e-pm" name="payment_method"><option value="">—</option>@foreach (\App\Commerce\Orders\Order::PAYMENT_METHODS as $k => $l)@continue($k === 'marketplace')<option value="{{ $k }}" @selected($order->payment_method === $k)>{{ $l }}</option>@endforeach</select></div>
                @endif
                @if ($manual)
                    <div><label for="e-discount">Desconto (R$)</label><input id="e-discount" name="discount" inputmode="decimal" value="{{ old('discount', number_format($order->discount_cents / 100, 2, ',', '')) }}"></div>
                    <div><label for="e-shipping">Frete (R$)</label><input id="e-shipping" name="shipping" inputmode="decimal" value="{{ old('shipping', number_format($order->shipping_cents / 100, 2, ',', '')) }}"></div>
                @endif
                @if (in_array($order->status, ['shipped', 'completed'], true))
                    <div><label for="e-tracking">Código de rastreio</label><input id="e-tracking" name="tracking_code" maxlength="60" value="{{ old('tracking_code', $order->tracking_code) }}"></div>
                @endif
                <div style="grid-column:1/-1;"><label for="e-notes">Observações</label><textarea id="e-notes" name="notes" rows="3" maxlength="2000">{{ old('notes', $order->notes) }}</textarea></div>
                <div><button type="submit">Salvar pedido</button></div>
            </form>
            <p class="helper" style="margin-bottom:0;">Outro tamanho ou cor: use "Trocar peça" acima. Para tirar ou acrescentar itens, cancele o pedido (as peças voltam ao estoque) e lance de novo.@unless ($manual) Desconto e frete deste pedido vêm do canal e não se editam aqui.@endunless</p>
        </div>
    @endif

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Linha do tempo</h3>
        <ul style="margin:0;padding-left:20px;line-height:1.9;">
            <li>Pedido feito em {{ $order->placed_at->format('d/m/Y H:i') }}</li>
            @if ($order->paid_at)<li>Pago em {{ $order->paid_at->format('d/m/Y H:i') }}</li>@endif
            @if ($order->shipped_at)<li>Enviado em {{ $order->shipped_at->format('d/m/Y H:i') }}{{ $order->tracking_code ? ' · rastreio '.$order->tracking_code : '' }}</li>@endif
            @if ($order->completed_at)<li>Concluído em {{ $order->completed_at->format('d/m/Y H:i') }}</li>@endif
            @if ($order->canceled_at)<li>Cancelado em {{ $order->canceled_at->format('d/m/Y H:i') }}</li>@endif
        </ul>
        @if ($order->notes)<p style="white-space:pre-line;margin-bottom:0;"><strong>Observações:</strong> {{ $order->notes }}</p>@endif
    </div>
</x-core::layout>
