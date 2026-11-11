@php($money = fn ($c) => \App\Commerce\Catalog\Money::format($c))
@php($first = $product->variants->first(fn ($v) => ($available[$v->id] ?? 0) > 0))
@php($prices = $product->variants->pluck('price_cents'))
@php($cover = $product->images->first(fn ($i) => $i->file)?->file?->url())
@php($catalog = $store->isCatalog())
@php($cta = $catalog ? 'Pedir pelo WhatsApp' : 'Adicionar ao carrinho')
<x-storefront::layout :title="$product->name" :image="$cover" :description="($prices->isEmpty() ? '' : ($prices->min() !== $prices->max() ? 'A partir de ' : '').$money($prices->min())).($first ? '' : ' · esgotado').($product->description ? ' · '.$product->description : '')">

    <div class="two">
        <div>
            @php($gallery = $product->imagesFor($first?->options)->merge($product->images)->unique('id')->filter(fn ($i) => $i->file)->values())
            @if ($gallery->isNotEmpty())
                {{-- Fotos da variação escolhida primeiro (ex.: as do nude); as outras ficam nas miniaturas. --}}
                <img id="main-photo" src="{{ $gallery->first()->file->url() }}" alt="{{ $product->name }}" style="width:100%;border-radius:14px;aspect-ratio:1;object-fit:cover;background:#f1eff6;">
                @if ($gallery->count() > 1)
                    <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;" data-thumbs>
                        @foreach ($gallery as $img)<img src="{{ $img->file->url() }}" alt="{{ $img->optionLabel() ?: $product->name }}" title="{{ $img->optionLabel() }}" data-option-name="{{ $img->option_name }}" data-option-value="{{ $img->option_value }}" style="width:72px;height:72px;object-fit:cover;border-radius:8px;cursor:pointer;" onclick="document.getElementById('main-photo').src = this.src">@endforeach
                    </div>
                @endif
            @else
                <div class="card muted" style="aspect-ratio:1;display:flex;align-items:center;justify-content:center;">sem foto</div>
            @endif
        </div>
        <div>
            <h1 style="margin-top:0;">{{ $product->name }}</h1>
            @if ($product->brand)<div class="muted">{{ $product->brand }}</div>@endif

            @if ($catalog)
            <form method="GET" action="{{ route('commerce.storefront.ask', [$store->slug, $product->id]) }}" target="_blank" style="margin-top:16px;display:grid;gap:14px;">
            @else
            <form method="POST" action="{{ route('commerce.storefront.cart.add', $store->slug) }}" style="margin-top:16px;display:grid;gap:14px;">
                @csrf
            @endif
                @if ($product->variants->count() > 1 && $product->hasGrid())
                    {{-- Uma escolha por característica (Cor, Tamanho; no conjunto, o tamanho de cada peça). O select completo abaixo continua valendo sem JavaScript. --}}
                    @php($optionNames = array_values($product->option_names))
                    @php($pickerData = $product->variants->map(fn ($v) => ['id' => $v->id, 'options' => array_map(fn ($n) => (string) ($v->options[$n] ?? ''), $optionNames), 'price' => $money($v->price_cents), 'left' => max(0, $available[$v->id] ?? 0)])->values())
                    <div data-picker hidden><div style="display:grid;gap:14px;">
                        @foreach ($optionNames as $i => $name)
                            @php($values = $product->variants->map(fn ($v) => (string) ($v->options[$name] ?? ''))->filter()->unique()->values())
                            <div>
                                <label for="opt-{{ $i }}">{{ $name }}</label>
                                <select id="opt-{{ $i }}" data-option="{{ $i }}">
                                    @foreach ($values as $value)
                                        <option value="{{ $value }}" @selected(($first?->options[$name] ?? null) === $value)>{{ str_starts_with($value, $name.' ') ? mb_substr($value, mb_strlen($name) + 1) : $value }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        <div><span class="price" style="font-size:1.5rem;" data-picker-price></span> <span class="muted" data-picker-stock></span></div>
                    </div></div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const variants = @json($pickerData);
                            const names = @json($optionNames);
                            const picker = document.querySelector('[data-picker]');
                            const full = document.getElementById('variant_id');
                            const selects = [...picker.querySelectorAll('[data-option]')];
                            const button = picker.closest('form').querySelector('button[type="submit"]');
                            picker.hidden = false;
                            full.closest('div').hidden = true;
                            function sync() {
                                const chosen = selects.map(function (s) { return s.value; });
                                const v = variants.find(function (x) { return x.options.every(function (o, i) { return o === chosen[i]; }); });
                                picker.querySelector('[data-picker-price]').textContent = v ? v.price : '';
                                picker.querySelector('[data-picker-stock]').textContent = !v ? 'Combinação indisponível' : (v.left === 0 ? 'Esgotado' : (v.left <= 3 ? 'Últimas ' + v.left + ' unidades' : ''));
                                if (v) { full.value = v.id; }
                                // Foto da cor (ou outra opção) escolhida, se tiver.
                                const main = document.getElementById('main-photo');
                                const photo = main && [...document.querySelectorAll('[data-thumbs] img')].find(function (img) { const i = names.indexOf(img.dataset.optionName); return i >= 0 && chosen[i] === img.dataset.optionValue; });
                                if (photo) { main.src = photo.src; }
                                button.disabled = !v || v.left === 0;
                                button.textContent = !v || v.left === 0 ? 'Indisponível' : @json($cta);
                            }
                            selects.forEach(function (s) { s.addEventListener('change', sync); });
                            sync();
                        });
                    </script>
                @endif
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
                <button class="btn" type="submit" @disabled(! $first)>{{ $first ? $cta : 'Esgotado' }}</button>
                @if ($catalog)<div class="muted">Abre o WhatsApp da loja com o seu pedido escrito. A loja confirma a disponibilidade, a entrega e o pagamento com você.</div>@endif
            </form>

            @if ($product->description)<div style="margin-top:24px;white-space:pre-line;">{{ $product->description }}</div>@endif
        </div>
    </div>
</x-storefront::layout>
