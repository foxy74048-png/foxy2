<?php

namespace App\Commerce\Assistant;

use App\Commerce\Access;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\Listing;
use App\Commerce\Finance\Payable;
use App\Commerce\Finance\Receivable;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Supply\SupplyBatch;
use App\Platform\Memberships\Membership;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * O Foxy Commerce como sistema anfitrião da Foxy AI (docs/09, seção 9): perfil, manual e consultas de leitura.
 * As consultas rodam aqui, no contexto da loja, com as permissões de quem pergunta. A IA nunca toca no banco.
 */
class CommerceHost
{
    public const SLUG = 'foxy-commerce';

    /** Consulta → permissão do Commerce exigida. */
    public const TOOLS = [
        'vendas_resumo' => 'commerce.reports.view',
        'vendas_mais_vendidos' => 'commerce.reports.view',
        'pedidos_pendentes' => 'commerce.orders.manage',
        'pedido_buscar' => 'commerce.orders.manage',
        'estoque_baixo' => 'commerce.stock.view',
        'anuncios_com_erro' => 'commerce.channels.manage',
        'contas_a_receber' => 'commerce.finance.manage',
        'contas_a_pagar' => 'commerce.finance.manage',
        'remessas_em_aberto' => 'commerce.catalog.manage',
        'cliente_buscar' => 'commerce.orders.manage',
    ];

    public const TOOL_LABELS = [
        'vendas_resumo' => 'Financeiro', 'vendas_mais_vendidos' => 'Financeiro', 'pedidos_pendentes' => 'Pedidos',
        'pedido_buscar' => 'Pedidos', 'estoque_baixo' => 'Estoque', 'anuncios_com_erro' => 'Anúncios',
        'contas_a_receber' => 'Financeiro', 'contas_a_pagar' => 'Financeiro', 'remessas_em_aberto' => 'Remessas', 'cliente_buscar' => 'Clientes',
    ];

    /** @return string[] consultas que esta pessoa pode usar */
    public static function toolsFor(?Membership $membership): array
    {
        return array_keys(array_filter(self::TOOLS, fn (string $permission) => Access::allows($membership, $permission)));
    }

    public static function profile(): array
    {
        return [
            'name' => 'Foxy Commerce',
            'description' => 'Hub de vendas multicanal: produtos com grade e kits, estoque único, anúncios e pedidos do Mercado Livre e outros marketplaces, pedidos do balcão e WhatsApp, nota fiscal e painel financeiro.',
            'tone' => 'Direto e prático, tratando a pessoa por "você". Fala como quem entende de loja e marketplace.',
        ];
    }

    public static function knowledge(): string
    {
        return <<<'MD'
        # Manual do Foxy Commerce

        O menu fica à esquerda: Painel, Relatórios, Pedidos, Clientes, Financeiro, WhatsApp, Produtos, Categorias, Anúncios, Marketplaces, Estoque, Loja própria, Equipe, Assinatura e Suporte. Cada pessoa vê só o que o papel dela permite. Toda lista e relatório tem os botões Planilha e PDF no alto (exportam o que está filtrado).

        ## Papéis
        - **Dono(a):** tudo, inclusive assinatura e equipe.
        - **Gerente:** produtos, anúncios, marketplaces, estoque, pedidos (inclusive cancelar) e relatórios.
        - **Atendente:** registra pedidos, consulta produtos e estoque. Não vê o financeiro nem cancela pedidos.
        - Convidar alguém: Equipe › e-mail e papel › Enviar convite. A pessoa cria o próprio login pelo link (vale 7 dias).

        ## Produtos e kits
        - **Novo produto:** Produtos › Novo produto › nome, preço e a grade (ex.: Tamanho "P, M, G" e Cor "Azul, Preto"). O sistema cria uma variante para cada combinação, com SKU.
        - **Editar SKU, preço, custo e código de barras:** abra o produto › tabela de variantes › Salvar variantes.
        - **Tamanho novo:** abra o produto › "Acrescentar à grade" › só as combinações novas são criadas.
        - **Kit:** Produtos › Novo kit › peças por SKU e quantidade. O kit não tem estoque próprio: o disponível vem das peças, e vender um kit baixa cada peça.
        - **Fotos:** abra o produto › Fotos › até 20, JPG/PNG/WEBP. Em "Estas fotos são de", marque a cor (ex.: Cor: Nude): na loja e no Mercado Livre, cada cor mostra as suas fotos. "Capa" escolhe a primeira. Produto sem foto aparece com a etiqueta "sem foto".
        - **Conjunto com tamanho de cada peça** (ex.: sutiã 44 com calcinha M): Produtos › Novo kit › "Conjunto com tamanho de cada peça" › escolha os produtos e marque "combinar só a mesma Cor". Cada combinação baixa as peças certas. Tamanho novo numa peça: abra o conjunto › "Criar combinações que faltam".
        - **Desativar:** some das listas e da loja sem apagar o histórico. "Reativar" traz de volta.
        - **Origem e fornecedora:** no produto, Origem (própria, comprado ou consignado) e Fornecedora.
        - Peso, medidas e NCM ficam em "Dados para envio e nota" (os marketplaces pedem).

        ## Estoque
        - **Chegou mercadoria:** Estoque › Entrada de mercadoria › SKU e quantidade de cada item.
        - **Contagem, perda ou brinde:** Estoque › clique no SKU › "Mexer no estoque" (Entrada, Saída ou Contagem).
        - **Estoque mínimo:** Estoque › clique no SKU › Estoque mínimo. Abaixo disso aparece como "Baixo".
        - Disponível = físico − reservado. Reservado = pedidos aguardando pagamento.
        - **Estoque negativo:** acontece quando o mesmo último item vende em dois canais quase juntos. Confira o físico e faça uma Contagem.
        - Toda mudança de estoque atualiza sozinha os anúncios no ar.

        ## Pedidos
        - **Venda no balcão, WhatsApp ou Instagram:** Pedidos › Novo pedido › canal; cliente (busque pelo nome ou telefone; mostra se ela deve algo; nome novo vira cliente nova); itens: produto e depois tamanho/cor (mostra quanto tem). "+ Produto que não está cadastrado" cadastra na hora. "Já está pago" baixa o estoque; sem isso, as peças ficam reservadas por 48 h.
        - **Crediário / fiado:** no novo pedido, forma de pagamento "Crediário / fiado", número de parcelas e 1º vencimento. A peça sai do estoque e as parcelas ficam em Financeiro › A receber, no nome da cliente.
        - **Trocar tamanho ou cor:** abra o pedido › Trocar peça › peça que volta e peça nova. A devolvida volta ao estoque e a nova sai.
        - **Editar observação, frete, desconto ou forma de pagamento:** abra o pedido › Editar dados do pedido.
        - **Confirmar pagamento:** abra o pedido › Confirmar pagamento.
        - **Andamento:** abra o pedido › Separando › Enviado (com código de rastreio) › Concluído.
        - **Cancelar:** abra o pedido › Cancelar pedido (só dono e gerente). As peças voltam ao estoque.
        - Pedidos dos marketplaces entram sozinhos, já pagos, com a taxa do canal.
        - **Planilha:** Pedidos › Baixar planilha (abre no Excel, com os filtros da tela).

        ## Nota fiscal
        - O Foxy ainda não emite nota. Emitiu no seu emissor ou no marketplace? Abra o pedido › Nota fiscal › anexe o XML ou cole a chave de acesso (44 números).
        - Pedidos › filtro "Vendidos sem nota" mostra o que falta.

        ## Marketplaces e anúncios
        - **Conectar:** Marketplaces › Conectar Mercado Livre › entre com a sua conta do Mercado Livre e autorize.
        - **Anunciar:** abra o produto › Anunciar (ou Anúncios › Novo anúncio) › conta, título (no Mercado Livre até 60 caracteres), preço no canal e variantes › Salvar e publicar.
        - **Categoria:** em branco, o Mercado Livre sugere pelo título.
        - **Atributos que a categoria exige** (gênero, tabela de medidas...): no anúncio, um por linha, "CÓDIGO=valor" (ex.: GENDER=Feminino, SIZE_GRID_ID=123456). LISTING_TYPE=gold_pro publica como Premium (padrão: Clássico).
        - **Anúncio com erro:** a mensagem do canal aparece no anúncio. Corrija e clique "Tentar publicar de novo".
        - **"Precisa de atenção" na conta:** em geral é pedido com SKU que não existe no Foxy. Cadastre o SKU; o pedido entra na próxima conferência (a cada 10 minutos).
        - Shopee, Magalu e Shein: em breve.

        ## Painel financeiro (dono e gerente)
        - Filtros de período, canal, forma de pagamento e categoria, sempre no topo.
        - Lucro bruto = faturamento − frete − taxas dos canais − custo das mercadorias. Não inclui despesas fixas.
        - Para o lucro ficar certo, preencha o custo de cada variante (abra o produto).

        ## Financeiro (dono e gerente)
        - **A receber:** parcelas do crediário e contas avulsas. "Recebi" dá baixa no valor todo; recebeu uma parte, em outra data ou quer escolher a forma? Abra a conta › Registrar recebimento (o que faltar continua em aberto). Lançou errado? "Desfazer recebimento".
        - **A pagar:** fornecedor, aluguel, etc. Uma vez, parcelado (divide o valor) ou todo mês. Cancelar pode levar as próximas da série.
        - **Fluxo de caixa:** entradas e saídas previstas por semana, com as vencidas na primeira linha.
        - O Painel avisa: contas vencidas, o que vence em 7 dias e acertos de consignação chegando.

        ## WhatsApp da loja (dono e gerente)
        - **Conectar:** WhatsApp › Gerar QR Code › no celular da loja: WhatsApp › Aparelhos conectados › Conectar um aparelho.
        - **Cobrar:** em Financeiro › A receber (ou na ficha da cliente) › Cobrar. Conectado: sai pelo número da loja, com o valor e o Pix copia e cola. Sem conexão: abre o seu WhatsApp com o texto pronto.
        - **Lembretes automáticos:** WhatsApp › ligue e escolha dias antes, no dia e dias depois de vencer. Saem das 9h às 19h, uma vez por etapa. Os textos se editam ali ({cliente}, {valor}, {vencimento}, {pix}...).

        ## Remessas de fornecedor e consignação
        - **Importar:** Estoque › Remessas de fornecedor › envie o PDF do comprovante, o **XML da nota fiscal** (vale para qualquer fornecedora; peça o XML a ela) ou cole o texto do comprovante. Diga se o preço é o de venda (com a sua comissão) ou o custo (com a sua margem) — ou deixe "Como na última remessa desta fornecedora".
        - **Conferir e lançar:** por produto, consignado ou comprado, categoria, preço e custo › Lançar no estoque. Peças compradas viram conta a pagar no fechamento (nota com parcelas: uma conta por parcela, nas datas da nota).
        - **O sistema aprende:** a fornecedora é reconhecida pelo CNPJ (ou pelo nome) e cada peça pelo código de barras, pelo código do fornecedor ou pela descrição. Na próxima remessa, as peças já vêm no produto, cor e tamanho que você conferiu (marcadas "reconhecida"), inclusive o que você corrigiu; só as novas pedem atenção.
        - **Acerto da consignação:** abra a remessa › Acerto › "Devolver" já vem com o que sobrou › Fazer o acerto. O devolvido sai do estoque e o vendido vira conta a pagar à fornecedora.

        ## Importar planilhas e fotos (trazer o que a loja já tem)
        - **Onde:** menu Importar planilhas e fotos (ou Produtos › Importar planilha, Financeiro › Importar planilha). Aceita .xlsx (Excel/Google Planilhas), .csv e **PDF com tabela** (relatório de estoque ou de contas de outro sistema, planilha salva como PDF). PDF escaneado/foto não dá: passe para a planilha modelo. Cada opção tem "Baixar modelo". Sempre mostra uma prévia antes de lançar.
        - **Produtos e estoque:** uma linha por variação: Produto, Cor, Tamanho, Quantidade, Preço de venda (opcionais: Categoria, Custo, Código de barras, Fornecedora, Origem consignado/comprado). Mesmo nome = um produto com a grade. Na prévia, escolha "Somar" (entrada de mercadoria em lote) ou "Deixar igual" (migração de outro sistema / contagem do estoque de hoje). Também em Estoque › Entrada de mercadoria › "Entrada em lote por planilha ou PDF". Categoria que não existe é criada.
        - **Contas a receber (fiado):** Cliente, Valor, Vencimento (opcionais: WhatsApp, Descrição, Parcela, Situação aberto/pago). Uma linha por parcela; cliente achada pelo WhatsApp ou nome, senão cadastrada.
        - **Contas a pagar:** Descrição, Valor, Vencimento (opcionais: Fornecedor, Categoria, Situação).
        - **Fotos em lote:** Importar › Fotos em lote. O nome do arquivo diz o produto e a cor: "Sutiã Renda Floral Preto.jpg" vai para a cor Preto; "Sutiã Renda Floral.jpg" vale para todas; também aceita SKU ou código de barras. Envia uma por vez e mostra as que não reconheceu.

        ## Meu perfil (sua conta)

        - **Meu perfil** (no menu, embaixo do seu nome): trocar o nome, o e-mail de login (pede a senha atual; o e-mail novo precisa ser confirmado pelo link e o antigo recebe um aviso) e a senha (pede a atual; as outras sessões abertas são encerradas). Também mostra as suas lojas e o seu papel em cada uma. Os dados da loja (nome, WhatsApp, logo, Pix) ficam em Loja própria.


        ## Consignação (maletas)
        - **Maleta/sacolinha:** Consignação (maletas) › + Nova maleta › escolha a cliente ou revendedora (revendedora tem comissão %), a data do acerto e as peças — bipe o código de barras ou digite o nome. As peças ficam reservadas: não aparecem para venda até o acerto. "Mandar a lista no WhatsApp" envia a lista com os preços para a pessoa; Planilha/PDF é o romaneio.
        - **Reposição:** na maleta aberta › Acrescentar peças.
        - **Link da maleta (portal da revendedora):** na maleta, "Copiar link" ou "Ver como ela vê". A pessoa abre sem senha e vê as maletas com ela, quanto ganha se vender tudo, a comissão já ganha, os acertos e o que falta pagar (com o Pix da loja, se configurado). O link já vai na lista do WhatsApp. "Trocar o link" cria outro e o antigo para de abrir. Funciona mesmo sem a vitrine publicada.
        - **Acerto:** abra a maleta › confira o que voltou › "Recebi agora" (forma de pagamento) ou "Fica a receber" (parcelas) › Fazer o acerto. O que voltou fica disponível de novo; o que ficou vira um pedido no canal "Consignação (maleta)", com a comissão da revendedora como desconto, e entra nos relatórios.
        - **Painel:** maletas com acerto atrasado ou nos próximos 2 dias aparecem no Painel.
        - **Recebidas (fornecedoras):** Consignação › Recebidas mostra, por remessa em aberto, quanto já vendeu, quanto já deve à fornecedora, o seu lucro, quantos dias faltam para o acerto e as peças paradas (sem nenhuma venda).

        ## Loja própria
        - **Dois jeitos:** em Loja própria › "Como a loja funciona" escolha **Só catálogo (pedido pelo WhatsApp)** — a cliente vê fotos, preços, cores e tamanhos disponíveis e toca em "Pedir pelo WhatsApp", que abre a conversa com o pedido escrito; para publicar basta o WhatsApp da loja — ou **Loja com carrinho** (carrinho, entrega e Pix; precisa do Pix e de uma forma de entrega).
        - "Mostrar só o que tem disponível" esconde da vitrine o que esgotou; o estoque do sistema manda.
        - **Divulgar:** em Loja própria e na tela de cada produto há "Compartilhar no WhatsApp" e "Copiar link"; o link chega com a foto, o nome e o preço.
        - Loja com carrinho: Pix (chave, nome e cidade), entregas e Publicar. O endereço é ecommerce.foxy.net.br/nome-da-loja. A cliente paga por Pix direto para a loja; confirme o pagamento no pedido.
        - O rodapé tem o aviso de Privacidade (LGPD) da loja.

        ## Assinatura
        - Assinatura › escolha o plano › pagamento no Stripe. Assinando durante o teste grátis, a cobrança começa quando o teste acaba.
        - Teste vencido ou assinatura cancelada: a loja fica só para consulta (nada é apagado) até assinar.

        ## Erros comuns
        - **"Não achamos o SKU X nesta loja":** o SKU não existe ou foi digitado diferente. Confira em Produtos.
        - **"O seu plano permite até N ...":** limite do plano (pessoas, produtos ou marketplaces). Mude de plano em Assinatura ou arquive produtos parados.
        - **"A loja está só para consulta":** o teste acabou ou a assinatura foi cancelada.
        MD;
    }

    public static function tools(): array
    {
        $date = ['type' => 'string', 'description' => 'Data no formato AAAA-MM-DD'];
        $channel = ['type' => 'string', 'enum' => array_keys(Order::CHANNELS), 'description' => 'Canal de venda (opcional)'];

        return [
            ['name' => 'vendas_resumo', 'risk' => 'read', 'permission' => 'commerce.reports.view',
                'description' => 'Faturamento, pedidos, ticket médio, lucro bruto, margem, taxas dos canais e cancelamentos entre duas datas, com o total de cada canal.',
                'input_schema' => ['type' => 'object', 'properties' => ['inicio' => $date, 'fim' => $date, 'canal' => $channel], 'required' => ['inicio', 'fim']]],
            ['name' => 'vendas_mais_vendidos', 'risk' => 'read', 'permission' => 'commerce.reports.view',
                'description' => 'Produtos mais vendidos entre duas datas (quantidade, faturamento e lucro).',
                'input_schema' => ['type' => 'object', 'properties' => ['inicio' => $date, 'fim' => $date, 'canal' => $channel, 'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]], 'required' => ['inicio', 'fim']]],
            ['name' => 'pedidos_pendentes', 'risk' => 'read', 'permission' => 'commerce.orders.manage',
                'description' => 'Pedidos que pedem ação agora: aguardando pagamento, para separar e para enviar.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'pedido_buscar', 'risk' => 'read', 'permission' => 'commerce.orders.manage',
                'description' => 'Busca um pedido pelo número no Foxy ou pelo número no marketplace e mostra situação, itens e cliente.',
                'input_schema' => ['type' => 'object', 'properties' => ['numero' => ['type' => 'string', 'maxLength' => 40]], 'required' => ['numero']]],
            ['name' => 'estoque_baixo', 'risk' => 'read', 'permission' => 'commerce.stock.view',
                'description' => 'Variantes com estoque negativo, zerado ou no mínimo.',
                'input_schema' => ['type' => 'object', 'properties' => ['limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]]]],
            ['name' => 'contas_a_receber', 'risk' => 'read', 'permission' => 'commerce.finance.manage',
                'description' => 'Quem está devendo: contas a receber em aberto (crediário/fiado e avulsas), vencidas primeiro, com cliente, telefone, valor, vencimento e dias de atraso; e o total vencido.',
                'input_schema' => ['type' => 'object', 'properties' => ['so_vencidas' => ['type' => 'boolean'], 'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]]]],
            ['name' => 'contas_a_pagar', 'risk' => 'read', 'permission' => 'commerce.finance.manage',
                'description' => 'Contas a pagar em aberto até uma data (padrão: 30 dias), vencidas primeiro, com fornecedor, categoria e valor.',
                'input_schema' => ['type' => 'object', 'properties' => ['ate' => $date]]],
            ['name' => 'remessas_em_aberto', 'risk' => 'read', 'permission' => 'commerce.catalog.manage',
                'description' => 'Remessas de fornecedor ainda não acertadas (consignação): fornecedora, fechamento, peças que vieram, quanto ainda há em estoque e a estimativa do valor do vendido a pagar no acerto.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []]],
            ['name' => 'cliente_buscar', 'risk' => 'read', 'permission' => 'commerce.orders.manage',
                'description' => 'Ficha de uma cliente pelo nome ou telefone: contato, total comprado, últimos pedidos e o que deve em aberto.',
                'input_schema' => ['type' => 'object', 'properties' => ['busca' => ['type' => 'string', 'maxLength' => 80]], 'required' => ['busca']]],
            ['name' => 'anuncios_com_erro', 'risk' => 'read', 'permission' => 'commerce.channels.manage',
                'description' => 'Anúncios que o marketplace recusou e contas de marketplace que precisam de atenção, com a mensagem do canal.',
                'input_schema' => ['type' => 'object', 'properties' => (object) []]],
        ];
    }

    /**
     * Executa uma consulta no contexto da loja. $allowed = consultas que esta pessoa pode usar.
     */
    public function run(string $tool, array $input, array $allowed): mixed
    {
        if (! in_array($tool, $allowed, true)) {
            throw ValidationException::withMessages(['tool' => 'Você não tem acesso a essa informação.']);
        }

        return match ($tool) {
            'vendas_resumo' => $this->salesSummary($input),
            'vendas_mais_vendidos' => (new FinanceReport($this->period($input)))->topProducts((int) ($input['limite'] ?? 10))
                ->map(fn ($p) => ['produto' => $p['label'], 'quantidade' => $p['quantity'], 'faturamento' => self::money($p['revenue']), 'lucro_bruto' => self::money($p['profit'])])->all(),
            'pedidos_pendentes' => $this->pendingOrders(),
            'pedido_buscar' => $this->findOrder((string) $input['numero']),
            'estoque_baixo' => $this->lowStock((int) ($input['limite'] ?? 20)),
            'anuncios_com_erro' => $this->listingProblems(),
            'contas_a_receber' => $this->receivables((bool) ($input['so_vencidas'] ?? false), (int) ($input['limite'] ?? 20)),
            'contas_a_pagar' => $this->payables($input['ate'] ?? null),
            'remessas_em_aberto' => $this->openBatches(),
            'cliente_buscar' => $this->findCustomer((string) $input['busca']),
        };
    }

    private function period(array $input): array
    {
        return ['periodo' => 'personalizado', 'de' => Carbon::parse($input['inicio'])->format('Y-m-d'), 'ate' => Carbon::parse($input['fim'])->format('Y-m-d'), 'canal' => $input['canal'] ?? null];
    }

    private function salesSummary(array $input): array
    {
        $report = new FinanceReport($this->period($input));
        $k = $report->kpis();

        return [
            'periodo' => $report->from->format('d/m/Y').' a '.$report->to->format('d/m/Y'),
            'faturamento' => self::money($k['revenue']), 'pedidos' => $k['orders'], 'ticket_medio' => self::money($k['ticket']),
            'lucro_bruto' => self::money($k['profit']), 'margem' => $k['margin'].'%', 'taxas_dos_canais' => self::money($k['fees']),
            'custo_das_mercadorias' => self::money($k['cost']), 'cancelamentos' => $k['canceled_count'],
            'variacao_faturamento_vs_periodo_anterior' => $k['delta_revenue'] === null ? 'sem base de comparação' : $k['delta_revenue'].'%',
            'por_canal' => $report->byChannel()->map(fn ($c) => ['canal' => $c['label'], 'faturamento' => self::money($c['revenue']), 'pedidos' => $c['orders'], 'margem' => $c['margin'].'%'])->all(),
        ];
    }

    private function pendingOrders(): array
    {
        $list = fn (string $status) => Order::with('customer')->where('status', $status)->oldest('placed_at')->limit(15)->get()
            ->map(fn (Order $o) => ['numero' => $o->number, 'canal' => $o->channelLabel(), 'cliente' => $o->customer?->name, 'total' => self::money($o->total_cents), 'desde' => $o->placed_at->format('d/m H:i')])->all();

        return ['aguardando_pagamento' => $list('awaiting_payment'), 'para_separar' => $list('paid'), 'separando_para_enviar' => $list('in_fulfillment')];
    }

    private function findOrder(string $number): array
    {
        $order = Order::with('items', 'customer')
            ->where(fn ($q) => $q->where('external_id', $number)->orWhere('number', ctype_digit(ltrim($number, '#')) ? (int) ltrim($number, '#') : 0))
            ->first();

        return $order ? [
            'numero' => $order->number, 'no_canal' => $order->external_id, 'canal' => $order->channelLabel(), 'situacao' => $order->statusLabel(),
            'data' => $order->placed_at->format('d/m/Y H:i'), 'cliente' => $order->customer?->name, 'total' => self::money($order->total_cents),
            'rastreio' => $order->tracking_code, 'itens' => $order->items->map(fn ($i) => $i->quantity.' × '.$i->name.' ('.$i->sku.')')->all(),
        ] : ['encontrado' => false];
    }

    private function lowStock(int $limit): array
    {
        return StockLevel::with('variant.product')
            ->whereHas('variant', fn ($q) => $q->where('status', 'active'))
            ->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhereRaw('min_stock is not null and on_hand - reserved <= min_stock'))
            ->orderByRaw('on_hand - reserved')->limit(min(50, max(1, $limit)))->get()
            ->map(fn (StockLevel $l) => ['sku' => $l->variant->sku, 'produto' => $l->variant->fullName(), 'fisico' => $l->on_hand, 'disponivel' => $l->available(), 'minimo' => $l->min_stock])->all();
    }

    private function listingProblems(): array
    {
        return [
            'anuncios' => Listing::with('connection')->where('status', 'error')->limit(20)->get()
                ->map(fn (Listing $l) => ['titulo' => $l->title, 'canal' => $l->connection->label(), 'mensagem_do_canal' => $l->last_error])->all(),
            'contas' => ChannelConnection::where('status', 'needs_attention')->get()
                ->map(fn ($c) => ['canal' => $c->label(), 'conta' => $c->name, 'mensagem' => $c->last_error])->all(),
        ];
    }

    private function receivables(bool $overdueOnly, int $limit): array
    {
        $base = Receivable::with('customer')->open();
        $rows = (clone $base)->when($overdueOnly, fn ($q) => $q->where('due_date', '<', today()->toDateString()))
            ->orderBy('due_date')->limit(min(50, max(1, $limit)))->get();

        return [
            'total_vencido' => self::money((int) (clone $base)->where('due_date', '<', today()->toDateString())->sum('amount_cents')),
            'total_em_aberto' => self::money((int) (clone $base)->sum('amount_cents')),
            'contas' => $rows->map(fn ($r) => ['cliente' => $r->customer?->name, 'telefone' => $r->customer?->phone, 'descricao' => trim($r->description.' '.$r->installmentLabel()),
                'valor' => self::money($r->amount_cents), 'vencimento' => $r->due_date->format('d/m/Y'), 'dias_de_atraso' => $r->daysLate(),
                'lembretes_enviados' => count($r->reminders ?? [])])->all(),
        ];
    }

    private function payables(?string $until): array
    {
        $until = $until ? Carbon::parse($until)->toDateString() : today()->addDays(30)->toDateString();
        $rows = Payable::open()->where('due_date', '<=', $until)->orderBy('due_date')->limit(50)->get();

        return [
            'ate' => Carbon::parse($until)->format('d/m/Y'), 'total' => self::money((int) $rows->sum('amount_cents')),
            'contas' => $rows->map(fn ($p) => ['descricao' => trim($p->description.' '.$p->installmentLabel()), 'fornecedor' => $p->supplier,
                'categoria' => Payable::CATEGORIES[$p->category] ?? $p->category, 'valor' => self::money($p->amount_cents),
                'vencimento' => $p->due_date->format('d/m/Y'), 'vencida' => $p->isOverdue()])->all(),
        ];
    }

    private function openBatches(): array
    {
        return SupplyBatch::with('items.variant')->where('status', 'aberta')->orderBy('closing_on')->get()->map(function ($batch) {
            $consigned = $batch->items->where('mode', 'consignado');
            $available = app(StockService::class)->availability($consigned->pluck('variant')->filter());
            $left = $consigned->sum(fn ($i) => max(0, min($i->quantity, $available[$i->variant_id] ?? 0)));
            $owed = $consigned->sum(fn ($i) => ($i->quantity - max(0, min($i->quantity, $available[$i->variant_id] ?? 0))) * $i->cost_cents);

            return ['remessa' => $batch->label(), 'fechamento' => $batch->closing_on?->format('d/m/Y'), 'pecas_consignadas' => $consigned->sum('quantity'),
                'ainda_em_estoque' => $left, 'estimativa_a_pagar_no_acerto' => self::money($owed)];
        })->all();
    }

    private function findCustomer(string $term): array
    {
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';
        $customer = Customer::where(fn ($q) => $q->whereLike('name', $like)->orWhereLike('phone', $like))->first();
        if (! $customer) {
            return ['encontrada' => false];
        }
        $orders = $customer->orders()->latest('placed_at')->limit(5)->get();
        $open = Receivable::open()->where('customer_id', $customer->id)->orderBy('due_date')->get();

        return [
            'nome' => $customer->name, 'whatsapp' => $customer->phone, 'cidade' => $customer->city,
            'total_comprado' => self::money((int) $customer->orders()->whereIn('status', Order::SOLD)->sum('total_cents')),
            'ultimos_pedidos' => $orders->map(fn ($o) => '#'.$o->number.' em '.$o->placed_at->format('d/m/Y').' — '.self::money($o->total_cents).' ('.$o->statusLabel().')')->all(),
            'deve_em_aberto' => self::money((int) $open->sum('amount_cents')),
            'parcelas_em_aberto' => $open->map(fn ($r) => $r->due_date->format('d/m/Y').': '.self::money($r->amount_cents).($r->isOverdue() ? ' (vencida)' : ''))->all(),
        ];
    }

    private static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
