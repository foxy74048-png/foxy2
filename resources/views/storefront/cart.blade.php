<x-storefront::layout title="Carrinho">
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    <h1 style="margin-top:0;">Carrinho</h1>

    @if ($lines->isEmpty())
        <div class="card">Seu carrinho está vazio. <a href="{{ route('commerce.storefront.home', $store->slug) }}">Ver produtos</a></div>
    @else
        <form method="POST" action="{{ route('commerce.storefront.cart.update', $store->slug) }}" class="card">
            @csrf
            <table>
                <thead><tr><th>Produto</th><th style="width:110px;">Qtd.</th><th style="text-align:right;">Total</th></tr></thead>
                <tbody>
                    @foreach ($lines as $l)
                        <tr>
                            <td>{{ $l['variant']->fullName() }}<div class="muted">{{ $money($l['variant']->price_cents) }} cada@if ($l['quantity'] > $l['available']) · <strong style="color:#8a1c1c;">só {{ $l['available'] }} disponível(is)</strong>@endif</div></td>
                            <td><input type="number" name="items[{{ $l['variant']->id }}]" value="{{ $l['quantity'] }}" min="0" max="99" aria-label="Quantidade de {{ $l['variant']->fullName() }}"></td>
                            <td style="text-align:right;white-space:nowrap;">{{ $money($l['total_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:12px;flex-wrap:wrap;">
                <button class="btn ghost" type="submit">Atualizar (0 remove)</button>
                <div><span class="muted">Subtotal</span> <span class="price">{{ $money($lines->sum('total_cents')) }}</span></div>
            </div>
        </form>
        <div style="margin-top:16px;text-align:right;"><a class="btn" href="{{ route('commerce.storefront.checkout', $store->slug) }}">Finalizar pedido</a></div>
    @endif
</x-storefront::layout>
