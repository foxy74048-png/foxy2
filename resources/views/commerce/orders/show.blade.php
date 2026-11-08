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
