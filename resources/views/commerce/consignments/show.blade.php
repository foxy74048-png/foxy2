<x-core::layout :title="$consignment->label()" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions>
        @include('commerce.export.buttons')
        @if ($whatsapp)<a class="button secondary" href="{{ $whatsapp }}" target="_blank" rel="noopener">Mandar a lista no WhatsApp</a>@endif
        <a class="button secondary" href="{{ route('commerce.consignments') }}">Voltar às maletas</a>
    </x-slot:actions>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($pct = rtrim(rtrim(number_format($consignment->commission_percent, 2, ',', ''), '0'), ','))

    @include('commerce.status')

    <div class="grid cards">
        <div class="card"><div class="muted">{{ $consignment->kind === 'revendedora' ? 'Revendedora' : 'Cliente' }}</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $consignment->customer?->name }}</h2><div class="helper">{{ $consignment->customer?->phone ?: 'sem WhatsApp' }}{{ $consignment->kind === 'revendedora' ? ' · comissão de '.$pct.'%' : '' }}</div></div>
        <div class="card"><div class="muted">Peças</div><h2 style="margin:4px 0;">{{ $consignment->pieces() }}</h2><div class="helper">{{ $money($consignment->valueCents()) }} pelo preço de venda</div></div>
        <div class="card"><div class="muted">{{ $consignment->status === 'aberta' ? 'Acerto até' : 'Acertada em' }}</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $consignment->status === 'aberta' ? ($consignment->due_on?->format('d/m/Y') ?? '—') : $consignment->settled_at?->format('d/m/Y') }}</h2>
            <div class="helper">saiu em {{ $consignment->sent_on->format('d/m/Y') }}@if ($consignment->isLate()) · <span style="color:var(--danger-text);">atrasado</span>@endif @if ($consignment->order) · <a href="{{ route('commerce.orders.show', $consignment->order) }}">pedido #{{ $consignment->order->number }}</a>@endif</div></div>
    </div>
    @if ($consignment->notes)<div class="alert info" style="margin-top:16px;">{{ $consignment->notes }}</div>@endif

    @if ($consignment->status === 'aberta')
        <form class="card" method="POST" action="{{ route('commerce.consignments.settle', $consignment) }}" style="margin-top:16px;" onsubmit="return confirm('Fazer o acerto? O que voltou fica disponível de novo e o que ficou vira venda.');">
            @csrf
            <h3 style="margin-top:0;">Acerto</h3>
            <p class="helper" style="margin-top:0;">Confira o que <b>voltou</b>. O que não voltou conta como vendido{{ $consignment->kind === 'revendedora' ? ', menos a comissão de '.$pct.'%' : '' }}.</p>
            <div class="table-wrap"><table>
                <thead><tr><th>Peça</th><th style="text-align:right;">Levou</th><th style="width:110px;">Voltou</th><th style="text-align:right;">Ficou</th><th style="text-align:right;">Valor</th></tr></thead>
                <tbody>
                    @foreach ($consignment->items as $item)
                        <tr data-settle data-qty="{{ $item->quantity }}" data-price="{{ $item->price_cents }}">
                            <td>{{ $item->name }}<div class="helper">{{ $item->variant?->gtin ?: $item->variant?->sku }} · {{ $money($item->price_cents) }}</div></td>
                            <td style="text-align:right;">{{ $item->quantity }}</td>
                            <td><input name="returns[{{ $item->id }}]" type="number" min="0" max="{{ $item->quantity }}" value="{{ old('returns.'.$item->id, $item->quantity) }}" aria-label="Voltou" data-back></td>
                            <td style="text-align:right;" data-sold></td>
                            <td style="text-align:right;white-space:nowrap;" data-value></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="4" style="text-align:right;">Vendido</td><td style="text-align:right;white-space:nowrap;" id="gross"></td></tr>
                    @if ($consignment->kind === 'revendedora')<tr><td colspan="4" style="text-align:right;">Comissão da revendedora ({{ $pct }}%)</td><td style="text-align:right;white-space:nowrap;" id="commission"></td></tr>@endif
                    <tr><td colspan="4" style="text-align:right;"><strong>{{ $consignment->kind === 'revendedora' ? 'Ela te paga' : 'A receber' }}</strong></td><td style="text-align:right;white-space:nowrap;"><strong id="net"></strong></td></tr>
                </tfoot>
            </table></div>
            <div class="form-grid" style="align-items:end;margin-top:12px;">
                <div>
                    <label for="payment">Pagamento</label>
                    <select id="payment" name="payment"><option value="pago">Recebi agora</option><option value="receber" @selected(old('payment') === 'receber')>Fica a receber (fiado/parcelas)</option></select>
                </div>
                <div data-pay="pago"><label for="method">Forma</label><select id="method" name="method">@foreach (array_diff_key(\App\Commerce\Orders\Order::PAYMENT_METHODS, ['crediario' => 1, 'marketplace' => 1]) as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div data-pay="receber"><label for="installments">Parcelas</label><input id="installments" name="installments" type="number" min="1" max="{{ \App\Commerce\Finance\FinanceService::MAX_INSTALLMENTS }}" value="{{ old('installments', 1) }}"></div>
                <div data-pay="receber"><label for="first_due">1º vencimento</label><input id="first_due" name="first_due" type="date" value="{{ old('first_due', today()->addMonth()->toDateString()) }}"></div>
                <div><button type="submit">Fazer o acerto</button></div>
            </div>
            <script>
                (function () {
                    const money = (c) => 'R$ ' + (c / 100).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                    const pct = {{ (float) $consignment->commission_percent }};
                    const sync = function () {
                        let gross = 0;
                        document.querySelectorAll('[data-settle]').forEach(function (row) {
                            const qty = +row.dataset.qty, back = Math.min(qty, Math.max(0, parseInt(row.querySelector('[data-back]').value, 10) || 0));
                            row.querySelector('[data-sold]').textContent = qty - back;
                            row.querySelector('[data-value]').textContent = money((qty - back) * row.dataset.price);
                            gross += (qty - back) * row.dataset.price;
                        });
                        const commission = Math.round(gross * pct / 100);
                        document.getElementById('gross').textContent = money(gross);
                        if (document.getElementById('commission')) document.getElementById('commission').textContent = '− ' + money(commission);
                        document.getElementById('net').textContent = money(gross - commission);
                    };
                    const pay = document.getElementById('payment');
                    const payment = function () { document.querySelectorAll('[data-pay]').forEach((el) => { el.style.display = el.dataset.pay === pay.value ? '' : 'none'; }); };
                    document.addEventListener('input', function (e) { if (e.target.matches('[data-back]')) sync(); });
                    pay.addEventListener('change', payment); sync(); payment();
                })();
            </script>
        </form>

        <details class="card" style="margin-top:16px;" @if ($errors->has('items')) open @endif>
            <summary style="cursor:pointer;font-weight:600;">Acrescentar peças (reposição)</summary>
            <form method="POST" action="{{ route('commerce.consignments.add', $consignment) }}" class="stack" style="margin-top:12px;">
                @csrf
                @include('commerce.consignments._picker', ['pickable' => $pickable])
                <div><button type="submit">Acrescentar à maleta</button></div>
            </form>
        </details>
    @else
        <div class="card" style="margin-top:16px;">
            <h3 style="margin-top:0;">Como foi o acerto</h3>
            <div class="table-wrap"><table>
                <thead><tr><th>Peça</th><th style="text-align:right;">Levou</th><th style="text-align:right;">Voltou</th><th style="text-align:right;">Ficou</th><th style="text-align:right;">Valor vendido</th></tr></thead>
                <tbody>
                    @foreach ($consignment->items as $item)
                        <tr><td>{{ $item->name }}</td><td style="text-align:right;">{{ $item->quantity }}</td><td style="text-align:right;">{{ $item->returned }}</td><td style="text-align:right;">{{ $item->sold() }}</td><td style="text-align:right;white-space:nowrap;">{{ $money($item->sold() * $item->price_cents) }}</td></tr>
                    @endforeach
                </tbody>
            </table></div>
        </div>
    @endif
</x-core::layout>
