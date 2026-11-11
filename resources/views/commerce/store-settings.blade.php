<x-core::layout title="Loja própria" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>
    @php($d = $settings->delivery ?? [])
    @php($url = route('commerce.storefront.home', $settings->slug))

    @include('commerce.status')

    <div class="card" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;">
        <div>
            <h3 style="margin:0;">{{ $settings->isPublished() ? ($settings->isCatalog() ? 'Seu catálogo está no ar' : 'Sua loja está no ar') : 'Sua loja ainda não está publicada' }}</h3>
            <div class="helper">Endereço: <a href="{{ $url }}" target="_blank" rel="noopener">{{ $url }}</a></div>
            @if ($settings->isPublished())
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
                    <a class="button secondary" href="https://wa.me/?text={{ rawurlencode(($settings->isCatalog() ? 'Veja o catálogo da ' : 'Conheça a loja da ').$settings->name.":\n".$url) }}" target="_blank" rel="noopener">Compartilhar no WhatsApp</a>
                    <a class="button secondary" href="{{ $url }}" data-copy="{{ $url }}" onclick="if (navigator.clipboard) { event.preventDefault(); navigator.clipboard.writeText(this.dataset.copy); this.textContent = 'Link copiado'; }">Copiar link</a>
                </div>
            @endif
        </div>
        <form method="POST" action="{{ route('commerce.store.publish') }}">
            @csrf
            <input type="hidden" name="publish" value="{{ $settings->isPublished() ? 0 : 1 }}">
            <button type="submit" @class(['secondary' => $settings->isPublished()])>{{ $settings->isPublished() ? 'Tirar do ar' : 'Publicar loja' }}</button>
        </form>
    </div>

    <form method="POST" action="{{ route('commerce.store.settings.update') }}" enctype="multipart/form-data" class="stack" style="margin-top:16px;">
        @csrf
        <div class="card stack">
            <h3 style="margin:0;">Como a loja funciona</h3>
            @php($mode = old('mode', $settings->mode ?? 'loja'))
            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;"><input type="radio" name="mode" value="catalogo" style="width:auto;margin-top:4px;" @checked($mode === 'catalogo')><span><b>Só catálogo (pedido pelo WhatsApp)</b><br><span class="helper">A cliente vê fotos, preços, cores e tamanhos disponíveis e toca em "Pedir pelo WhatsApp": abre a conversa com você com o pedido escrito. Sem carrinho e sem Pix no site. Para publicar, basta o WhatsApp da loja.</span></span></label>
            <label style="display:flex;gap:10px;align-items:flex-start;font-weight:400;"><input type="radio" name="mode" value="loja" style="width:auto;margin-top:4px;" @checked($mode === 'loja')><span><b>Loja com carrinho</b><br><span class="helper">A cliente monta o carrinho, escolhe a entrega e recebe o Pix com o valor; as peças ficam reservadas. Precisa do Pix e de uma forma de entrega.</span></span></label>
            <label style="display:flex;gap:10px;align-items:center;font-weight:400;"><input type="hidden" name="hide_sold_out" value="0"><input type="checkbox" name="hide_sold_out" value="1" style="width:auto;" @checked(old('hide_sold_out', $settings->hide_sold_out))> Mostrar só o que tem disponível (esconder o que esgotou)</label>
        </div>

        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Vitrine</h3></div>
            <div><label for="name">Nome da loja</label><input id="name" name="name" value="{{ old('name', $settings->name) }}" required maxlength="80"></div>
            <div><label for="whatsapp">WhatsApp da loja</label><input id="whatsapp" name="whatsapp" value="{{ old('whatsapp', $settings->whatsapp) }}" maxlength="30" placeholder="11 99999-9999"><div class="helper">As clientes mandam o pedido para este número.</div></div>
            <div><label for="primary_color">Cor da loja</label><input id="primary_color" type="color" name="primary_color" value="{{ old('primary_color', $settings->color()) }}" style="height:44px;"></div>
            <div>
                <label for="logo">Logo</label>
                <input id="logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
                @if ($settings->logo)<img src="{{ $settings->logo->url() }}" alt="" style="height:40px;margin-top:6px;">@endif
            </div>
            <div style="grid-column: 1 / -1;"><label for="about">Sobre a loja (aparece no rodapé)</label><textarea id="about" name="about" rows="2" maxlength="2000">{{ old('about', $settings->about) }}</textarea></div>
        </div>

        <div class="card form-grid">
            <div style="grid-column: 1 / -1;">
                <h3 style="margin:0;">Pagamento por Pix (direto na sua conta)</h3>
                <p class="helper" style="margin:4px 0 0;">Usado na loja com carrinho. No modo catálogo é opcional.</p>
                <p class="helper" style="margin:4px 0 0;">O comprador vê o Pix copia e cola e o QR com o valor do pedido. O dinheiro cai na sua conta; você confirma o pagamento no pedido. A Foxy não recebe nem guarda pagamentos.</p>
            </div>
            <div><label for="pix_key">Chave Pix</label><input id="pix_key" name="pix_key" value="{{ old('pix_key', $settings->pix_key) }}" maxlength="77" placeholder="CNPJ, e-mail, celular ou chave aleatória"></div>
            <div><label for="pix_name">Nome do recebedor (até 25)</label><input id="pix_name" name="pix_name" value="{{ old('pix_name', $settings->pix_name) }}" maxlength="25"></div>
            <div><label for="pix_city">Cidade do recebedor (até 15)</label><input id="pix_city" name="pix_city" value="{{ old('pix_city', $settings->pix_city) }}" maxlength="15"></div>
            <div style="grid-column: 1 / -1;"><label for="payment_instructions">Instruções de pagamento (opcional)</label><textarea id="payment_instructions" name="payment_instructions" rows="2" maxlength="1000" placeholder="Ex.: envie o comprovante pelo WhatsApp.">{{ old('payment_instructions', $settings->payment_instructions) }}</textarea></div>
        </div>

        <div class="card form-grid">
            <div style="grid-column: 1 / -1;"><h3 style="margin:0;">Entrega</h3><p class="helper" style="margin:4px 0 0;">Usado na loja com carrinho. No modo catálogo você combina pelo WhatsApp.</p></div>
            <div style="grid-column: 1 / -1;display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                <div class="stack">
                    <label style="display:flex;gap:8px;align-items:center;"><input type="hidden" name="delivery[pickup]" value="0"><input type="checkbox" name="delivery[pickup]" value="1" style="width:auto;" @checked(old('delivery.pickup', $d['pickup'] ?? false))> Retirar na loja</label>
                    <input name="delivery[pickup_address]" value="{{ old('delivery.pickup_address', $d['pickup_address'] ?? '') }}" maxlength="255" placeholder="Endereço e horário de retirada">
                </div>
                <div class="stack">
                    <label style="display:flex;gap:8px;align-items:center;"><input type="hidden" name="delivery[local]" value="0"><input type="checkbox" name="delivery[local]" value="1" style="width:auto;" @checked(old('delivery.local', $d['local'] ?? false))> Entrega local</label>
                    <input name="delivery[local_fee]" value="{{ old('delivery.local_fee', \App\Commerce\Catalog\Money::input($d['local_fee_cents'] ?? null)) }}" inputmode="decimal" placeholder="Taxa (R$)">
                    <input name="delivery[local_area]" value="{{ old('delivery.local_area', $d['local_area'] ?? '') }}" maxlength="255" placeholder="Bairros/cidades atendidos">
                </div>
                <div class="stack">
                    <label style="display:flex;gap:8px;align-items:center;"><input type="hidden" name="delivery[shipping]" value="0"><input type="checkbox" name="delivery[shipping]" value="1" style="width:auto;" @checked(old('delivery.shipping', $d['shipping'] ?? false))> Envio (Correios/transportadora)</label>
                    <input name="delivery[shipping_fee]" value="{{ old('delivery.shipping_fee', \App\Commerce\Catalog\Money::input($d['shipping_fee_cents'] ?? null)) }}" inputmode="decimal" placeholder="Frete fixo (R$)">
                    <input name="delivery[shipping_note]" value="{{ old('delivery.shipping_note', $d['shipping_note'] ?? '') }}" maxlength="255" placeholder="Ex.: postagem em até 2 dias úteis">
                </div>
            </div>
        </div>

        <div><button type="submit">Salvar loja</button></div>
    </form>
</x-core::layout>
