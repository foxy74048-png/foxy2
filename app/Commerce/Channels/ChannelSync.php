<?php

namespace App\Commerce\Channels;

use App\Commerce\Catalog\Variant;
use App\Commerce\Fiscal\FiscalDocument;
use App\Commerce\Fiscal\NfeXml;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tudo o que vai e volta entre o Foxy e os marketplaces (docs/09, seção 6.2), sempre pelo conector do canal.
 * Roda dentro do contexto da loja dona da conexão.
 */
class ChannelSync
{
    public function __construct(private StockService $stock, private OrderService $orders, private ContextStore $contexts) {}

    /** Executa $callback no contexto (de sistema) da organização. */
    public function as(string $organizationId, string $source, callable $callback): mixed
    {
        $organization = Organization::findOrFail($organizationId);

        return $this->contexts->run(ExecutionContext::forSystem($organization, $source), $callback);
    }

    public function publish(Listing $listing): void
    {
        $listing->loadMissing('connection', 'variants.variant');
        $available = $this->stock->availability($listing->variants->pluck('variant'));
        $variants = $listing->variants->mapWithKeys(fn (ListingVariant $lv) => [$lv->variant_id => [
            'sku' => $lv->variant->sku, 'options' => $lv->variant->options, 'quantity' => max(0, $available[$lv->variant_id] ?? 0), 'price_cents' => $listing->price_cents,
        ]])->all();

        try {
            $result = Channels::connector($listing->connection->channel)->publish($listing->connection, $listing, $variants);
        } catch (ChannelError $e) {
            $listing->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            Audit::record('commerce.listing.publish_failed', 'failed', $listing, ['error' => mb_substr($e->getMessage(), 0, 300)]);

            return;
        }

        foreach ($listing->variants as $lv) {
            $lv->update(['external_variation_id' => $result['variations'][$lv->variant_id] ?? null, 'pushed_quantity' => $variants[$lv->variant_id]['quantity']]);
        }
        $listing->update(['external_id' => $result['external_id'], 'status' => 'active', 'last_error' => null, 'synced_at' => now()]);
        Audit::record('commerce.listing.published', subject: $listing, metadata: ['external_id' => $result['external_id']]);
    }

    public function update(Listing $listing): void
    {
        $this->attempt($listing, fn ($connector) => $connector->update($listing->connection, $listing));
    }

    public function pause(Listing $listing, bool $paused): void
    {
        if ($this->attempt($listing, fn ($connector) => $connector->pause($listing->connection, $listing, $paused))) {
            $listing->update(['status' => $paused ? 'paused' : 'active']);
        }
    }

    /** Manda ao canal o disponível de cada variação do anúncio, só o que mudou desde o último envio. */
    public function pushStock(Listing $listing): void
    {
        if ($listing->status !== 'active') {
            return;
        }

        $listing->loadMissing('connection', 'variants.variant');
        $available = $this->stock->availability($listing->variants->pluck('variant'));
        $changed = [];
        foreach ($listing->variants as $lv) {
            $quantity = max(0, $available[$lv->variant_id] ?? 0);
            if ($lv->pushed_quantity !== $quantity) {
                $changed[$lv->id] = [$lv->external_variation_id ?? $listing->external_id, $quantity];
            }
        }
        if ($changed === []) {
            return;
        }

        $sent = $this->attempt($listing, fn ($connector) => $connector->pushStock($listing->connection, $listing, collect($changed)->mapWithKeys(fn ($c) => [$c[0] => $c[1]])->all()));
        if ($sent) {
            foreach ($changed as $id => [, $quantity]) {
                ListingVariant::whereKey($id)->update(['pushed_quantity' => $quantity]);
            }
            $listing->update(['synced_at' => now()]);
        }
    }

    /**
     * Traz um pedido do canal para a fila do Foxy (idempotente pelo id no canal). Pedido cancelado no canal
     * cancela aqui e devolve o estoque.
     */
    public function importOrder(ChannelConnection $connection, string $resource): ?Order
    {
        $external = Channels::connector($connection->channel)->fetchOrder($connection, $resource);
        $existing = Order::where('channel', $connection->channel)->where('external_id', $external->externalId)->first();

        if ($existing) {
            if ($external->status === 'canceled' && $existing->status !== 'canceled') {
                $this->orders->cancel($existing, 'cancelado no '.$connection->label());
            }

            return $existing;
        }
        if ($external->status !== 'paid') {
            return null; // Só entra quando o canal confirma o pagamento.
        }

        $items = [];
        foreach ($external->items as $item) {
            $variant = Variant::where('sku', Str::upper($item['sku']))->first()
                ?? throw new ChannelError("O pedido {$external->externalId} do {$connection->label()} tem o SKU {$item['sku']}, que não existe no Foxy. Cadastre o SKU (ou corrija no anúncio) e o pedido entra na próxima conferência.");
            $items[] = ['variant' => $variant, 'quantity' => $item['quantity'], 'unit_price_cents' => $item['unit_price_cents']];
        }

        $customerId = null;
        if ($external->buyerName) {
            $customerId = ($external->buyerEmail ? Customer::where('email', $external->buyerEmail)->value('id') : null)
                ?? Customer::create(['name' => $external->buyerName, 'email' => $external->buyerEmail, 'city' => $external->city, 'state' => $external->state])->id;
        }

        return $this->orders->place([
            'channel' => $connection->channel,
            'external_id' => $external->externalId,
            'customer_id' => $customerId,
            'items' => $items,
            'paid' => true,
            'payment_method' => 'marketplace',
            'fee_cents' => $external->feeCents,
            'shipping_cents' => $external->shippingCents,
            'placed_at' => $external->placedAt,
        ]);
    }

    /** Processa um aviso da caixa de entrada. Erro: guarda e tenta de novo na conferência. */
    public function processInbox(InboxItem $item): void
    {
        $connection = ChannelConnection::withoutGlobalScopes()->where('channel', $item->channel)->where('external_account_id', (string) $item->external_account_id)->first();
        if (! $connection || $connection->status === 'disconnected') {
            $item->update(['processed_at' => now(), 'last_error' => 'Conta não conectada a nenhuma loja.']);

            return;
        }

        try {
            $this->as($connection->organization_id, 'canal', function () use ($connection, $item) {
                if (str_contains($item->topic, 'order')) {
                    $this->importOrder(ChannelConnection::findOrFail($connection->id), $item->resource);
                }
            });
            $item->update(['processed_at' => now(), 'attempts' => $item->attempts + 1, 'last_error' => null]);
        } catch (Throwable $e) {
            $item->update(['attempts' => $item->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
            if ($e instanceof ChannelError) {
                $connection->attention($e->getMessage());
            } else {
                report($e);
            }
        }
    }

    /** Conferência periódica: renova o token e busca pedidos que algum aviso possa ter perdido. */
    public function reconcile(ChannelConnection $connection): void
    {
        $connector = Channels::connector($connection->channel);

        try {
            if ($connection->token_expires_at && $connection->token_expires_at->lt(now()->addMinutes(30))) {
                $connector->refresh($connection);
            }
            $since = ($connection->last_synced_at ?? now()->subDays(2))->copy()->subHour();
            foreach ($connector->recentOrders($connection, $since) as $resource) {
                $this->importOrder($connection, $resource);
            }
            $this->importInvoices($connection);
            $connection->update(['last_synced_at' => now(), 'status' => 'active', 'last_error' => null]);
        } catch (ChannelError $e) {
            $connection->attention($e->getMessage());
        }
    }

    /** Busca no canal as notas dos pedidos vendidos nos últimos 30 dias que ainda estão sem nota. */
    public function importInvoices(ChannelConnection $connection): int
    {
        $connector = Channels::connector($connection->channel);
        if (! $connector instanceof ProvidesInvoices) {
            return 0;
        }

        $found = 0;
        $orders = Order::sold()->where('channel', $connection->channel)->whereNotNull('external_id')
            ->where('placed_at', '>=', now()->subDays(30))
            ->whereDoesntHave('fiscalDocuments')->limit(50)->get();
        foreach ($orders as $order) {
            $invoice = $connector->fetchInvoice($connection, $order->external_id);
            if ($invoice && NfeXml::validKey($invoice['access_key'] ?? '')) {
                FiscalDocument::firstOrCreate(['access_key' => $invoice['access_key']], [
                    'order_id' => $order->id, 'source' => $connection->channel, 'number' => $invoice['number'] ?? null,
                    'series' => $invoice['series'] ?? null, 'issued_at' => $invoice['issued_at'] ?? null, 'total_cents' => $invoice['total_cents'] ?? null,
                ]);
                $found++;
            }
        }

        return $found;
    }

    /** Chama o canal; em erro, marca o anúncio e devolve false. */
    private function attempt(Listing $listing, callable $call): bool
    {
        $listing->loadMissing('connection');

        try {
            $call(Channels::connector($listing->connection->channel));

            return true;
        } catch (ChannelError $e) {
            $listing->update(['last_error' => $e->getMessage()]);

            return false;
        }
    }
}
