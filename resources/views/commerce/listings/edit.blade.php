<x-core::layout :title="$listing->title" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    <x-slot:actions><a class="button secondary" href="{{ route('commerce.listings') }}">Voltar aos anúncios</a></x-slot:actions>

    @include('commerce.status')
    @if (session('error'))<div class="alert error" style="margin-bottom:16px;">{{ session('error') }}</div>@endif

    <div class="grid cards">
        <div class="card"><div class="muted">Situação</div><h2 style="margin:4px 0;font-size:1.2rem;">{{ $listing->statusLabel() }}</h2><div class="helper">{{ $listing->connection->label() }} · {{ $listing->connection->name }}</div></div>
        <div class="card"><div class="muted">No canal</div><h2 style="margin:4px 0;font-size:1.1rem;">{{ $listing->external_id ?? 'ainda não publicado' }}</h2><div class="helper">{{ $listing->synced_at ? 'atualizado '.$listing->synced_at->diffForHumans() : '' }}</div></div>
        <div class="card"><div class="muted">Produto</div><h2 style="margin:4px 0;font-size:1.1rem;"><a href="{{ route('commerce.products.edit', $listing->product) }}">{{ $listing->product->name }}</a></h2></div>
    </div>

    @if ($listing->last_error)<div class="alert error" style="margin-top:16px;"><strong>O canal respondeu:</strong> {{ $listing->last_error }}</div>@endif

    <div class="card" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap;">
        @if (in_array($listing->status, ['draft', 'error'], true))
            <form method="POST" action="{{ route('commerce.listings.publish', $listing) }}">@csrf<button type="submit">{{ $listing->status === 'error' ? 'Tentar publicar de novo' : 'Publicar' }}</button></form>
        @elseif ($listing->status === 'active')
            <form method="POST" action="{{ route('commerce.listings.pause', $listing) }}">@csrf<input type="hidden" name="paused" value="1"><button type="submit" class="secondary">Pausar no canal</button></form>
        @elseif ($listing->status === 'paused')
            <form method="POST" action="{{ route('commerce.listings.pause', $listing) }}">@csrf<input type="hidden" name="paused" value="0"><button type="submit">Reativar no canal</button></form>
        @elseif ($listing->status === 'publishing')
            <span class="helper">Publicando… atualize a página em instantes.</span>
        @endif
    </div>

    <form class="card form-grid" method="POST" action="{{ route('commerce.listings.update', $listing) }}" style="margin-top:16px;">
        @csrf
        <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Dados do anúncio</h3></div>
        <div style="grid-column: 1 / -1;">
            <label for="title">Título (até {{ config("commerce.channels.{$listing->connection->channel}.title_max") }} caracteres)</label>
            <input id="title" name="title" value="{{ old('title', $listing->title) }}" maxlength="150" required>
        </div>
        <div><label for="price">Preço no canal (R$)</label><input id="price" name="price" value="{{ old('price', \App\Commerce\Catalog\Money::input($listing->price_cents)) }}" inputmode="decimal" required></div>
        <div><label for="external_category">Categoria no canal</label><input id="external_category" name="external_category" value="{{ old('external_category', $listing->external_category) }}" maxlength="80"></div>
        <div style="grid-column: 1 / -1;">
                    <label for="channel_attributes">Atributos do canal (opcional, um por linha: CÓDIGO=valor)</label>
                    <textarea id="channel_attributes" name="channel_attributes" rows="3" maxlength="3000" placeholder="GENDER=Feminino&#10;SIZE_GRID_ID=123456&#10;LISTING_TYPE=gold_pro">{{ old('channel_attributes', collect($listing->getAttribute('attributes') ?? [])->map(fn ($v, $k) => $k.'='.$v)->implode("
")) }}</textarea>
                    <div class="helper">Quando o canal pedir um dado que falta, ele avisa o código na mensagem de erro. No Mercado Livre: LISTING_TYPE=gold_special (Clássico, padrão) ou gold_pro (Premium); moda pede tabela de medidas (SIZE_GRID_ID).</div>
                </div>
        <div style="grid-column: 1 / -1;"><button type="submit">Salvar{{ in_array($listing->status, ['active', 'paused'], true) ? ' e atualizar no canal' : '' }}</button></div>
    </form>

    <div class="card" style="margin-top:16px;">
        <h3 style="margin-top:0;">Estoque enviado ao canal</h3>
        <div class="table-wrap"><table>
            <thead><tr><th>SKU</th><th>Variante</th><th style="text-align:right;">Disponível no Foxy</th><th style="text-align:right;">Último enviado</th></tr></thead>
            <tbody>
                @foreach ($listing->variants as $lv)
                    <tr>
                        <td><a href="{{ route('commerce.stock.show', $lv->variant) }}">{{ $lv->variant->sku }}</a></td>
                        <td>{{ $lv->variant->label() ?: '—' }}</td>
                        <td style="text-align:right;">{{ max(0, $available[$lv->variant_id] ?? 0) }}</td>
                        <td style="text-align:right;">{{ $lv->pushed_quantity ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table></div>
        <p class="helper">O estoque vai sozinho: cada venda, entrada ou ajuste atualiza todos os anúncios em segundos.</p>
    </div>
</x-core::layout>
