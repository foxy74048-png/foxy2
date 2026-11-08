<x-storefront::layout :title="'Pedido #'.$order->number">
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    <h1 style="margin-top:0;">Pedido #{{ $order->number }}</h1>
    <p class="muted">Situação: <strong>{{ $order->statusLabel() }}</strong></p>

    <div class="two">
        <div class="card">
            @if ($pix)
                <h3 style="margin-top:0;">Pague com Pix</h3>
                <p>Valor: <span class="price">{{ $money($order->total_cents) }}</span></p>
                <img src="{{ $qr }}" alt="QR Code do Pix" style="width:220px;height:220px;display:block;margin:8px 0;">
                <label for="pix">Pix copia e cola</label>
                <textarea id="pix" rows="3" readonly onclick="this.select()">{{ $pix }}</textarea>
                <button class="btn ghost" type="button" style="margin-top:8px;" onclick="navigator.clipboard.writeText(document.getElementById('pix').value).then(() => this.textContent = 'Copiado!')">Copiar código</button>
                @if ($store->payment_instructions)<p style="white-space:pre-line;">{{ $store->payment_instructions }}</p>@endif
                <p class="muted">As peças ficam reservadas até {{ $order->reserved_until?->format('d/m H:i') }}. A loja confirma o pagamento e segue com o pedido.</p>
            @elseif ($order->status === 'awaiting_payment')
                <h3 style="margin-top:0;">Pagamento</h3>
                <p>A loja vai combinar o pagamento com você pelo WhatsApp.</p>
            @else
                <h3 style="margin-top:0;">Obrigado!</h3>
                <p>O pagamento já foi confirmado pela loja.</p>
            @endif
            @if ($whatsapp)<a class="btn" href="{{ $whatsapp }}" target="_blank" rel="noopener" style="margin-top:8px;">Enviar pedido pelo WhatsApp</a>@endif
        </div>

        <div class="card">
            <h3 style="margin-top:0;">Itens</h3>
            <table>
                @foreach ($order->items as $i)<tr><td>{{ $i->quantity }}× {{ $i->name }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($i->total_cents) }}</td></tr>@endforeach
                @if ($order->shipping_cents)<tr><td>Entrega</td><td style="text-align:right;">{{ $money($order->shipping_cents) }}</td></tr>@endif
                <tr><td><strong>Total</strong></td><td style="text-align:right;"><strong>{{ $money($order->total_cents) }}</strong></td></tr>
            </table>
            <p class="muted" style="white-space:pre-line;">{{ $order->notes }}</p>
        </div>
    </div>
</x-storefront::layout>
