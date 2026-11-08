<x-storefront::layout title="Finalizar pedido">
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($options = $store->deliveryOptions())
    <h1 style="margin-top:0;">Finalizar pedido</h1>

    <div class="two">
        <form method="POST" action="{{ route('commerce.storefront.place', $store->slug) }}" class="card" style="display:grid;gap:14px;">
            @csrf
            <div><label for="name">Seu nome</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="120" autocomplete="name"></div>
            <div><label for="phone">WhatsApp</label><input id="phone" name="phone" value="{{ old('phone') }}" required maxlength="30" autocomplete="tel" inputmode="tel"></div>
            <div><label for="email">E-mail (para receber o pedido)</label><input id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" autocomplete="email"></div>
            @if ($options)
                <div>
                    <label>Entrega</label>
                    @foreach ($options as $key => $o)
                        <label style="font-weight:400;display:flex;gap:8px;align-items:flex-start;margin:6px 0;">
                            <input type="radio" name="delivery" value="{{ $key }}" style="width:auto;margin-top:4px;" @checked(old('delivery', array_key_first($options)) === $key)>
                            <span>{{ $o['label'] }} — {{ $o['fee_cents'] ? $money($o['fee_cents']) : 'grátis' }}@if ($o['detail'])<br><span class="muted">{{ $o['detail'] }}</span>@endif</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <div><label for="address">Endereço (para entrega ou envio)</label><textarea id="address" name="address" rows="2" maxlength="500" autocomplete="street-address">{{ old('address') }}</textarea></div>
            <div><label for="city">Cidade</label><input id="city" name="city" value="{{ old('city') }}" maxlength="80"></div>
            <div><label for="notes">Observação</label><textarea id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea></div>
            <button class="btn" type="submit">Fazer pedido e ver o Pix</button>
            <p class="muted" style="margin:0;">O pagamento é por Pix direto para a loja. As peças ficam reservadas por 48 horas.</p>
        </form>

        <div class="card">
            <h3 style="margin-top:0;">Resumo</h3>
            <table>
                @foreach ($lines as $l)<tr><td>{{ $l['quantity'] }}× {{ $l['variant']->fullName() }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($l['total_cents']) }}</td></tr>@endforeach
                <tr><td><strong>Subtotal</strong></td><td style="text-align:right;"><strong>{{ $money($lines->sum('total_cents')) }}</strong></td></tr>
            </table>
            <p class="muted">A entrega escolhida é somada no total.</p>
        </div>
    </div>
</x-storefront::layout>
