<x-storefront::layout title="Privacidade">
    <div class="card" style="max-width:760px;line-height:1.7;">
        <h1 style="margin-top:0;">Como usamos os seus dados</h1>
        <p>Esta é a loja <strong>{{ $store->name }}</strong>. Para atender o seu pedido, pedimos alguns dados seus. Este aviso explica o que fazemos com eles, como manda a Lei Geral de Proteção de Dados (LGPD, Lei 13.709/2018).</p>

        <h3>Quais dados</h3>
        <p>Nome, WhatsApp, e-mail (se você informar), endereço e cidade de entrega, e o que você comprou.</p>

        <h3>Para quê</h3>
        <ul>
            <li>Registrar e separar o seu pedido, combinar a entrega ou o envio;</li>
            <li>Falar com você sobre o pedido e o pagamento (inclusive lembretes pelo WhatsApp, quando houver valor em aberto);</li>
            <li>Emitir nota fiscal e cumprir obrigações legais.</li>
        </ul>
        <p>A base legal é a execução do pedido que você fez e o cumprimento de obrigações legais. <strong>Não vendemos nem emprestamos os seus dados</strong> para ninguém.</p>

        <h3>Com quem ficam</h3>
        <p>Os dados ficam com a {{ $store->name }}, guardados no sistema Foxy Commerce, que a loja usa para organizar os pedidos (a Foxy só guarda e processa os dados em nome da loja). O pagamento é por Pix direto para a loja: nenhum dado de cartão ou banco passa por aqui.</p>

        <h3>Por quanto tempo</h3>
        <p>Enquanto você for cliente e pelo tempo que a lei exige para registros de venda e nota fiscal.</p>

        <h3>Seus direitos</h3>
        <p>Você pode pedir para ver, corrigir ou apagar os seus dados (menos o que a lei manda guardar), ou para não receber mais mensagens.
            @if ($store->whatsapp)É só falar com a loja pelo WhatsApp <strong>{{ $store->whatsapp }}</strong>.@else É só falar com a loja.@endif</p>

        <p class="muted" style="margin-bottom:0;"><a href="{{ route('commerce.storefront.home', $store->slug) }}">Voltar à loja</a></p>
    </div>
</x-storefront::layout>
