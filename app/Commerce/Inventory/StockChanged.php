<?php

namespace App\Commerce\Inventory;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * O disponível destas variantes mudou (inclui os kits que usam as peças). Os canais reagem empurrando o novo
 * saldo para os anúncios (fatia 7). Disparado depois que a transação grava.
 */
class StockChanged
{
    use Dispatchable;

    /** @param  string[]  $variantIds */
    public function __construct(public string $organizationId, public array $variantIds) {}
}
