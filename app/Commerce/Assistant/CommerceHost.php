<?php

namespace App\Commerce\Assistant;

use App\Commerce\Access;
use App\Commerce\Channels\ChannelConnection;
use App\Commerce\Channels\Listing;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Orders\Order;
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
    ];

    public const TOOL_LABELS = [
        'vendas_resumo' => 'Financeiro', 'vendas_mais_vendidos' => 'Financeiro', 'pedidos_pendentes' => 'Pedidos',
        'pedido_buscar' => 'Pedidos', 'estoque_baixo' => 'Estoque', 'anuncios_com_erro' => 'Anúncios',
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

        O menu fica à esquerda: Painel, Pedidos, Clientes, Produtos, Categorias, Anúncios, Marketplaces, Estoque, Equipe e Assinatura. Cada pessoa vê só o que o papel dela permite.

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
        - **Fotos:** abra o produto › Fotos › até 10, JPG/PNG/WEBP. A primeira é a capa.
        - **Arquivar:** some das listas sem apagar o histórico. "Reativar produto" traz de volta.
        - Peso, medidas e NCM ficam em "Dados para envio e nota" (os marketplaces pedem).

        ## Estoque
        - **Chegou mercadoria:** Estoque › Entrada de mercadoria › SKU e quantidade de cada item.
        - **Contagem, perda ou brinde:** Estoque › clique no SKU › "Mexer no estoque" (Entrada, Saída ou Contagem).
        - **Estoque mínimo:** Estoque › clique no SKU › Estoque mínimo. Abaixo disso aparece como "Baixo".
        - Disponível = físico − reservado. Reservado = pedidos aguardando pagamento.
        - **Estoque negativo:** acontece quando o mesmo último item vende em dois canais quase juntos. Confira o físico e faça uma Contagem.
        - Toda mudança de estoque atualiza sozinha os anúncios no ar.

        ## Pedidos
        - **Venda no balcão, WhatsApp ou Instagram:** Pedidos › Novo pedido › canal, cliente, itens por SKU. "Já está pago" baixa o estoque; sem isso, as peças ficam reservadas por 48 h.
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

    private static function money(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
