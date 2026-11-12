<x-storefront::layout :title="'Maletas de '.$customer->name" :bare="true">
    @php($reseller = $open->merge($settled)->contains('kind', 'revendedora'))
    <h1 style="margin-top:0;">Olá, {{ strtok($customer->name, ' ') }}!</h1>
    <p class="muted">Suas maletas com a <b>{{ $store->name }}</b>. Esta página é só sua: não compartilhe o link.</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin:16px 0;">
        <div class="card"><div class="muted">Peças com você</div><div class="price" style="font-size:1.5rem;">{{ $open->sum(fn ($c) => $c->pieces()) }}</div><div class="muted">{{ $money($open->sum(fn ($c) => $c->valueCents())) }} pelo preço de venda</div></div>
        @if ($reseller)
            <div class="card"><div class="muted">Se vender tudo, você ganha</div><div class="price" style="font-size:1.5rem;">{{ $money($potential) }}</div><div class="muted">de comissão</div></div>
            <div class="card"><div class="muted">Comissão já ganha</div><div class="price" style="font-size:1.5rem;">{{ $money($earned) }}</div><div class="muted">nos acertos anteriores</div></div>
        @endif
        @if ($receivables->isNotEmpty())
            <div class="card"><div class="muted">Falta pagar à loja</div><div class="price" style="font-size:1.5rem;">{{ $money($receivables->sum('amount_cents')) }}</div><div class="muted">próxima: {{ $next->due_date->format('d/m/Y') }}</div></div>
        @endif
    </div>

    @foreach ($open as $c)
        @php($pct = rtrim(rtrim(number_format($c->commission_percent, 2, ',', ''), '0'), ','))
        <div class="card" style="margin-bottom:16px;">
            <h2 style="margin:0;">Maleta #{{ $c->number }}</h2>
            <p class="muted" style="margin:4px 0 12px;">Recebida em {{ $c->sent_on->format('d/m/Y') }}@if ($c->due_on) · <b @if ($c->isLate()) style="color:#8a1c1c;" @endif>acerto até {{ $c->due_on->format('d/m/Y') }}</b>@endif{{ $c->kind === 'revendedora' ? ' · sua comissão: '.$pct.'%' : '' }}</p>
            <table>
                <tr><th>Peça</th><th style="text-align:right;">Qtd.</th><th style="text-align:right;">Preço</th>@if ($c->kind === 'revendedora')<th style="text-align:right;">Você ganha</th>@endif</tr>
                @foreach ($c->items as $i)
                    <tr><td>{{ $i->name }}</td><td style="text-align:right;">{{ $i->quantity }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($i->price_cents) }}</td>@if ($c->kind === 'revendedora')<td style="text-align:right;white-space:nowrap;">{{ $money((int) round($i->price_cents * $c->commission_percent / 100)) }} cada</td>@endif</tr>
                @endforeach
                <tr><td><b>Total</b></td><td style="text-align:right;"><b>{{ $c->pieces() }}</b></td><td style="text-align:right;white-space:nowrap;"><b>{{ $money($c->valueCents()) }}</b></td>@if ($c->kind === 'revendedora')<td style="text-align:right;white-space:nowrap;"><b>{{ $money((int) round($c->valueCents() * $c->commission_percent / 100)) }}</b></td>@endif</tr>
            </table>
            <p class="muted" style="margin-bottom:0;">No acerto, a loja confere com você o que voltou. O que ficou é a venda{{ $c->kind === 'revendedora' ? ', e você paga o valor menos a sua comissão' : '' }}.</p>
        </div>
    @endforeach

    @if ($receivables->isNotEmpty())
        <div class="two" style="margin-bottom:16px;">
            <div class="card">
                <h3 style="margin-top:0;">O que falta pagar</h3>
                <table>
                    @foreach ($receivables as $r)<tr><td>{{ $r->description }}{{ $r->installments ? ' ('.$r->installment.'/'.$r->installments.')' : '' }}</td><td>{{ $r->due_date->format('d/m/Y') }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($r->amount_cents) }}</td></tr>@endforeach
                </table>
            </div>
            @if ($pix)
                <div class="card">
                    <h3 style="margin-top:0;">Pagar a próxima com Pix</h3>
                    <p>Valor: <span class="price">{{ $money($next->amount_cents) }}</span> · vence {{ $next->due_date->format('d/m/Y') }}</p>
                    <img src="{{ $qr }}" alt="QR Code do Pix" style="width:200px;height:200px;display:block;margin:8px 0;">
                    <label for="pix">Pix copia e cola</label>
                    <textarea id="pix" rows="3" readonly onclick="this.select()">{{ $pix }}</textarea>
                    <p class="muted">Depois de pagar, avise a loja pelo WhatsApp: ela confirma o recebimento.</p>
                </div>
            @endif
        </div>
    @endif

    @if ($settled->isNotEmpty())
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin-top:0;">Acertos anteriores</h3>
            <table>
                <tr><th>Maleta</th><th>Acerto</th><th style="text-align:right;">Vendido</th>@if ($reseller)<th style="text-align:right;">Sua comissão</th>@endif<th style="text-align:right;">Valor do acerto</th></tr>
                @foreach ($settled as $c)
                    <tr><td>#{{ $c->number }}</td><td>{{ $c->settled_at?->format('d/m/Y') }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($c->order?->subtotal_cents) }}</td>@if ($reseller)<td style="text-align:right;white-space:nowrap;">{{ $money($c->order?->discount_cents) }}</td>@endif<td style="text-align:right;white-space:nowrap;">{{ $money($c->order?->total_cents) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @if ($chat)<a class="btn" href="{{ $chat }}" target="_blank" rel="noopener">Falar com a loja no WhatsApp</a>@endif
</x-storefront::layout>
