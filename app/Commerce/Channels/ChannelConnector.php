<?php

namespace App\Commerce\Channels;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Contrato de um marketplace (docs/09, seção 6.1). Adicionar canal = escrever um conector; o resto do Commerce
 * não muda. Só os conectores falam com APIs de marketplace. Erros do canal: lançar ChannelError com texto claro.
 */
interface ChannelConnector
{
    /** Página do canal onde o lojista autoriza o Foxy. $state volta no retorno (confere a sessão). */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * Troca o código do retorno pela conta e pelos tokens.
     *
     * @return array{external_account_id: string, name: ?string, access_token: string, refresh_token: ?string, expires_at: ?Carbon}
     */
    public function connect(string $code, string $redirectUri): array;

    /** Renova o token (quando o canal usa token com validade). Atualiza a conexão. */
    public function refresh(ChannelConnection $connection): void;

    /**
     * Publica o anúncio. Devolve o id no canal e o id de cada variação (por variant_id).
     *
     * @param  array<string, array{sku: string, options: ?array, quantity: int, price_cents: int}>  $variants
     * @return array{external_id: string, variations: array<string, string>}
     */
    public function publish(ChannelConnection $connection, Listing $listing, array $variants): array;

    /** Atualiza título, preço e dados do anúncio já publicado. */
    public function update(ChannelConnection $connection, Listing $listing): void;

    /** Pausa (true) ou reativa (false) o anúncio no canal. */
    public function pause(ChannelConnection $connection, Listing $listing, bool $paused): void;

    /**
     * Envia o disponível de cada variação.
     *
     * @param  array<string, int>  $quantities  external_variation_id (ou external_id sem variação) => quantidade
     */
    public function pushStock(ChannelConnection $connection, Listing $listing, array $quantities): void;

    /**
     * Lê um aviso recebido. null = aviso que não interessa.
     *
     * @return array{external_account_id: ?string, topic: string, resource: string}|null
     */
    public function parseNotification(Request $request): ?array;

    /** Busca o pedido na API (nunca confia no corpo do aviso). */
    public function fetchOrder(ChannelConnection $connection, string $resource): ExternalOrder;

    /**
     * Pedidos recentes, para a conferência periódica.
     *
     * @return string[] recursos/ids para fetchOrder
     */
    public function recentOrders(ChannelConnection $connection, Carbon $since): array;
}
