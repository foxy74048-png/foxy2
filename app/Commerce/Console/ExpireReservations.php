<?php

namespace App\Commerce\Console;

use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

/**
 * Pedido aguardando pagamento depois do prazo da reserva: cancela e devolve as peças ao disponível.
 */
class ExpireReservations extends Command
{
    protected $signature = 'commerce:reservas';

    protected $description = 'Cancela pedidos do Foxy Commerce não pagos dentro do prazo da reserva';

    public function handle(ContextStore $contexts, OrderService $orders): int
    {
        $expired = Order::withoutGlobalScopes()->where('status', 'awaiting_payment')
            ->whereNotNull('reserved_until')->where('reserved_until', '<', now())
            ->get(['id', 'organization_id'])->groupBy('organization_id');

        foreach ($expired as $organizationId => $ids) {
            $organization = Organization::find($organizationId);
            $contexts->run(ExecutionContext::forSystem($organization, 'reservas'), function () use ($ids, $orders) {
                foreach (Order::whereIn('id', $ids->pluck('id'))->get() as $order) {
                    $orders->cancel($order, 'pagamento não confirmado no prazo da reserva');
                }
            });
        }

        $this->info('Pedidos vencidos: '.$expired->flatten()->count());

        return self::SUCCESS;
    }
}
