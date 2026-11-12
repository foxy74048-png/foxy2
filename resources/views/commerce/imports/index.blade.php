<x-core::layout title="Importar" product="Foxy Commerce" :subtitle="$currentStore->name">
    <x-slot:nav>@include('commerce.nav')</x-slot:nav>

    @include('commerce.status')

    <p class="muted" style="margin-top:0;">Traga o que você já tem — da planilha, do caderno passado a limpo ou de outro sistema — sem digitar tudo de novo. Em todas as opções você <b>confere uma prévia</b> antes de lançar; nada entra sem a sua confirmação.</p>


    <div class="grid two" style="align-items:start;">
        @if ($canProducts)
            <div class="card stack" id="produtos">
                <h3 style="margin:0;">Produtos e estoque: migrar ou dar entrada em lote</h3>
                <p class="helper" style="margin:0;">Uma linha por variação: <b>Produto, Cor, Tamanho, Quantidade, Preço de venda</b>. Opcionais: Categoria, Custo, Código de barras, Fornecedora, Origem (consignado/comprado). Linhas com o mesmo nome viram <b>um produto</b> com a grade de cores e tamanhos; produto que já existe só ganha as variações novas e o estoque. Na prévia você escolhe: <b>somar</b> (entrada de mercadoria em lote) ou <b>deixar igual</b> (migração / contagem do estoque).</p>
                @include('commerce.imports._upload', ['type' => 'produtos', 'label' => 'Planilha ou PDF dos produtos'])
            </div>
        @endif

        @if ($canSupply)
            <div class="card stack">
                <h3 style="margin:0;">Comprovante da fornecedora (PDF)</h3>
                <p class="helper" style="margin:0;">Chegou a sacola da consignação (ex.: Miza) com o comprovante em PDF? O sistema lê as peças com código de barras, cor e tamanho, e no fechamento faz o acerto do que vendeu e do que volta.</p>
                <div><a class="button" href="{{ route('commerce.supply') }}#importar">Importar comprovante</a></div>
            </div>

            <div class="card stack">
                <h3 style="margin:0;">Fotos dos produtos em lote</h3>
                <p class="helper" style="margin:0;">Escolha várias fotos de uma vez. O <b>nome do arquivo</b> diz de qual produto e cor ela é: <code>sutia renda floral preto.jpg</code> vai para a cor Preto do Sutiã Renda Floral; <code>calcinha fio dental.jpg</code> vale para todas as cores. Também reconhece o SKU ou o código de barras.</p>
                <div><a class="button" href="{{ route('commerce.imports.photos') }}">Enviar fotos em lote</a></div>
            </div>
        @endif

        @if ($canFinance)
            <div class="card stack" id="receber">
                <h3 style="margin:0;">Contas a receber (fiado / crediário)</h3>
                <p class="helper" style="margin:0;">Quem te deve: <b>Cliente, Valor, Vencimento</b> — uma linha por parcela. Opcionais: WhatsApp, Descrição, Parcela (ex.: 2/3), Situação (aberto/pago). A cliente é achada pelo WhatsApp ou pelo nome; se não existir, é cadastrada. Depois é só ligar os lembretes do WhatsApp.</p>
                @include('commerce.imports._upload', ['type' => 'receber', 'label' => 'Planilha ou PDF do fiado'])
            </div>

            <div class="card stack" id="pagar">
                <h3 style="margin:0;">Contas a pagar</h3>
                <p class="helper" style="margin:0;">As contas da loja: <b>Descrição, Valor, Vencimento</b>. Opcionais: Fornecedor, Categoria (fornecedor, aluguel, embalagens, frete…), Situação (aberto/pago). Com elas, o fluxo de caixa já mostra o que vai sair.</p>
                @include('commerce.imports._upload', ['type' => 'pagar', 'label' => 'Planilha ou PDF das contas'])
            </div>
        @endif
    </div>

    <p class="helper" style="margin-top:16px;">Aceita <b>.xlsx</b> (Excel, Google Planilhas › Arquivo › Fazer download), <b>.csv</b> e <b>PDF com tabela</b> (relatório de estoque ou de contas exportado de outro sistema, ou planilha "salva como PDF"). PDF escaneado (foto do papel) não dá para ler: nesse caso, passe para a planilha modelo. Os títulos podem variar ("Qtd", "Quantidade", "Estoque"; "Preço", "Valor"…). Na dúvida, baixe o modelo, copie as suas colunas para ele e envie.</p>
</x-core::layout>
