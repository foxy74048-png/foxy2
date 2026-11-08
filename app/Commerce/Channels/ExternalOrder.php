<?php

namespace App\Commerce\Channels;

use Illuminate\Support\Carbon;

/**
 * Pedido como o canal informa, já traduzido pelo conector. Itens pelo SKU do Foxy (o conector manda o SKU
 * como "seller sku" ao publicar, então ele volta igual).
 */
final readonly class ExternalOrder
{
    /**
     * @param  array<int, array{sku: string, quantity: int, unit_price_cents: int}>  $items
     * @param  string  $status  paid | canceled | pending
     */
    public function __construct(
        public string $externalId,
        public string $status,
        public array $items,
        public Carbon $placedAt,
        public int $feeCents = 0,
        public int $shippingCents = 0,
        public ?string $buyerName = null,
        public ?string $buyerEmail = null,
        public ?string $city = null,
        public ?string $state = null,
    ) {}
}
