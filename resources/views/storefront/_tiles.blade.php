{{-- Grade de peças da loja (home e seleção): foto, nome, preço e "Esgotado". --}}
@php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
<div class="grid">
    @foreach ($products as $p)
        @php($prices = $p->variants->pluck('price_cents'))
        @php($inStock = $p->variants->sum(fn ($v) => max(0, $available[$v->id] ?? 0)))
        <a class="tile" href="{{ route('commerce.storefront.product', [$store->slug, $p->id]) }}">
            <div class="ph">@if ($p->images->first()?->file)<img src="{{ $p->images->first()->file->url() }}" alt="{{ $p->name }}" loading="lazy">@else sem foto @endif</div>
            <div class="info">
                <div>{{ $p->name }}</div>
                <div class="price">{{ $prices->min() !== $prices->max() ? 'a partir de ' : '' }}{{ $money($prices->min()) }}</div>
                @if ($inStock === 0)<div class="muted">Esgotado</div>@elseif ($inStock <= 3)<div class="muted">Últimas {{ $inStock }} {{ $inStock === 1 ? 'unidade' : 'unidades' }}</div>@endif
            </div>
        </a>
    @endforeach
</div>
