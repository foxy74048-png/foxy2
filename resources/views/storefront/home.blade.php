<x-storefront::layout>
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))

    @if ($categories->isNotEmpty())
        <nav class="chips" aria-label="Categorias">
            <a href="{{ route('commerce.storefront.home', $store->slug) }}" @class(['on' => empty($filters['categoria'])])>Tudo</a>
            @foreach ($categories as $c)<a href="{{ route('commerce.storefront.home', [$store->slug, 'categoria' => $c->id]) }}" @class(['on' => ($filters['categoria'] ?? '') === $c->id])>{{ $c->name }}</a>@endforeach
        </nav>
    @endif

    @if ($products->isEmpty())
        <div class="card">Nenhum produto encontrado.</div>
    @else
        <div class="grid">
            @foreach ($products as $p)
                @php($prices = $p->variants->pluck('price_cents'))
                @php($inStock = $p->variants->sum(fn ($v) => max(0, $available[$v->id] ?? 0)))
                <a class="tile" href="{{ route('commerce.storefront.product', [$store->slug, $p->id]) }}">
                    <div class="ph">@if ($p->images->first()?->file)<img src="{{ $p->images->first()->file->url() }}" alt="{{ $p->name }}" loading="lazy">@else sem foto @endif</div>
                    <div class="info">
                        <div>{{ $p->name }}</div>
                        <div class="price">{{ $prices->min() !== $prices->max() ? 'a partir de ' : '' }}{{ $money($prices->min()) }}</div>
                        @if ($inStock === 0)<div class="muted">Esgotado</div>@endif
                    </div>
                </a>
            @endforeach
        </div>
        <div style="margin-top:20px;">{{ $products->links() }}</div>
    @endif
</x-storefront::layout>
