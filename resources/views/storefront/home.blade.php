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
        @include('storefront._tiles')
        <div style="margin-top:20px;">{{ $products->links() }}</div>
    @endif
</x-storefront::layout>
