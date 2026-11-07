<?php

namespace App\Platform\Billing;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A assinatura mudou (pagamento, cancelamento, teste estendido...). O produto reage (ex.: conta no Foxy Admin).
 */
class SubscriptionChanged
{
    use Dispatchable;

    public function __construct(public Subscription $subscription) {}
}
