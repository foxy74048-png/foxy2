<?php

namespace App\Commerce\Channels;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Marketplace de mentira para os testes: guarda o que o Foxy mandou e devolve pedidos preparados.
 */
class FakeConnector implements ChannelConnector, ProvidesInvoices
{
    /** @var array<string, array> id do pedido no canal => nota */
    public array $invoices = [];

    public array $published = [];

    public array $updated = [];

    public array $paused = [];

    /** @var array<int, array{listing: string, quantities: array}> */
    public array $stockPushes = [];

    /** @var array<string, ExternalOrder> recurso => pedido */
    public array $orders = [];

    public array $recent = [];

    public ?string $failPublishWith = null;

    public int $refreshes = 0;

    public function authorizationUrl(string $state, string $redirectUri): string
    {
        return 'https://marketplace.test/autorizar?state='.$state.'&redirect_uri='.urlencode($redirectUri);
    }

    public function connect(string $code, string $redirectUri): array
    {
        if ($code === 'invalido') {
            throw new ChannelError('Código de autorização inválido.');
        }

        return ['external_account_id' => 'vendedor-'.$code, 'name' => 'LOJA_'.strtoupper($code), 'access_token' => 'tok-'.$code, 'refresh_token' => 'ref-'.$code, 'expires_at' => now()->addHours(6)];
    }

    public function refresh(ChannelConnection $connection): void
    {
        $this->refreshes++;
        $connection->update(['access_token' => 'tok-novo', 'token_expires_at' => now()->addHours(6)]);
    }

    public function publish(ChannelConnection $connection, Listing $listing, array $variants): array
    {
        if ($this->failPublishWith) {
            throw new ChannelError($this->failPublishWith);
        }

        $this->published[] = ['listing' => $listing->id, 'title' => $listing->title, 'price' => $listing->price_cents, 'variants' => $variants];
        $external = 'ANUNCIO-'.count($this->published);

        return ['external_id' => $external, 'variations' => collect($variants)->mapWithKeys(fn ($v, $id) => [$id => $external.'-'.$v['sku']])->all()];
    }

    public function update(ChannelConnection $connection, Listing $listing): void
    {
        $this->updated[] = ['listing' => $listing->id, 'title' => $listing->title, 'price' => $listing->price_cents];
    }

    public function pause(ChannelConnection $connection, Listing $listing, bool $paused): void
    {
        $this->paused[] = [$listing->id, $paused];
    }

    public function pushStock(ChannelConnection $connection, Listing $listing, array $quantities): void
    {
        $this->stockPushes[] = ['listing' => $listing->id, 'quantities' => $quantities];
    }

    public function parseNotification(Request $request): ?array
    {
        $data = $request->json()->all();

        return isset($data['topic'], $data['resource']) ? ['external_account_id' => $data['user_id'] ?? null, 'topic' => $data['topic'], 'resource' => $data['resource']] : null;
    }

    public function fetchOrder(ChannelConnection $connection, string $resource): ExternalOrder
    {
        return $this->orders[$resource] ?? throw new ChannelError("Pedido {$resource} não existe no canal.");
    }

    public function recentOrders(ChannelConnection $connection, Carbon $since): array
    {
        return $this->recent;
    }

    public function fetchInvoice(ChannelConnection $connection, string $externalOrderId): ?array
    {
        return $this->invoices[$externalOrderId] ?? null;
    }
}
