{{-- Seleção do catálogo: só as peças que a loja escolheu, com o estoque de agora. Cada peça abre a página normal. --}}
<x-storefront::layout :title="$selection->title" :image="$products->first()?->images->first()?->file?->url()" :description="'Seleção da '.$store->name.' para você: '.$products->count().' '.($products->count() === 1 ? 'peça' : 'peças').'.'">
    <div style="margin:8px 0 18px;">
        <h1 style="margin:0 0 4px;font-size:1.5rem;">{{ $selection->title }}</h1>
        <div class="muted">Peças separadas pela {{ $store->name }} para você. Toque numa peça para ver cores e tamanhos{{ $store->isCatalog() ? ' e pedir pelo WhatsApp' : '' }}.</div>
    </div>
    @if ($products->isEmpty())
        <div class="card">As peças desta seleção esgotaram. <a href="{{ route('commerce.storefront.home', $store->slug) }}">Veja o catálogo completo</a>@if ($chat) ou <a href="{{ $chat }}" target="_blank" rel="noopener">fale com a loja</a>@endif.</div>
    @else
        @include('storefront._tiles')
    @endif
    <div style="margin-top:20px;display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn ghost" href="{{ route('commerce.storefront.home', $store->slug) }}">Ver o catálogo completo</a>
        @if ($chat)<a class="btn" href="{{ $chat }}" target="_blank" rel="noopener">Falar com a loja no WhatsApp</a>@endif
    </div>
</x-storefront::layout>
