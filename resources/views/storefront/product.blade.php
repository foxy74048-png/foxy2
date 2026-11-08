<x-storefront::layout :title="$product->name">
    @php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
    @php($first = $product->variants->first(fn ($v) => ($available[$v->id] ?? 0) > 0))

    <div class="two">
        <div>
            @if ($product->images->first()?->file)
                <img src="{{ $product->images->first()->file->url() }}" alt="{{ $product->name }}" style="width:100%;border-radius:14px;aspect-ratio:1;object-fit:cover;background:#f1eff6;">
                @if ($product->images->count() > 1)
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
                        @foreach ($product->images->skip(1) as $img)<img src="{{ $img->file->url() }}" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:8px;">@endforeach
                    </div>
                @endif
            @else
                <div class="card muted" style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;">sem foto</div>
            @endif
        </div>
        <div>
            <h1 style="margin-top:0;">{{ $product->name }}</h1>
            @if ($product->brand)<div class="muted">{{ $product->brand }}</div>@endif

            <form method="POST" action="{{ route('commerce.storefront.cart.add', $store->slug) }}" style="margin-top:16px;display:grid;gap:14px;">
                @csrf
                @if ($product->variants->count() > 1)
                    <div>
                        <label for="variant_id">Escolha</label>
                        <select id="variant_id" name="variant_id">
                            @foreach ($product->variants as $v)
                                @php($left = max(0, $available[$v->id] ?? 0))
                                <option value="{{ $v->id }}" @disabled($left === 0) @selected($first?->id === $v->id)>{{ $v->label() }} — {{ $money($v->price_cents) }}{{ $left === 0 ? ' (esgotado)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="variant_id" value="{{ $product->variants->first()?->id }}">
                    <div class="price" style="font-size:1.5rem;">{{ $money($product->variants->first()?->price_cents) }}</div>
                @endif
                <div style="max-width:120px;"><label for="quantity">Quantidade</label><input id="quantity" type="number" name="quantity" value="1" min="1" max="99"></div>
                <button class="btn" type="submit" @disabled(! $first)>{{ $first ? 'Adicionar ao carrinho' : 'Esgotado' }}</button>
            </form>

            @if ($product->description)<div style="margin-top:24px;white-space:pre-line;">{{ $product->description }}</div>@endif
        </div>
    </div>
</x-storefront::layout>
