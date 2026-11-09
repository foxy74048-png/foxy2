<?php

namespace App\Commerce\Orders;

use App\Commerce\Catalog\KitComponent;
use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\Store;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única porta para criar pedidos e mudar o status (docs/09, seção 6.1). O estoque anda junto:
 * pago = baixa; aguardando pagamento = reserva (vence em 48 h); cancelado = devolve ou solta a reserva.
 */
class OrderService
{
    public const RESERVATION_HOURS = 48;

    /** Próximos passos permitidos a partir de cada status. */
    private const FLOW = [
        'paid' => ['in_fulfillment', 'shipped', 'completed'],
        'in_fulfillment' => ['shipped', 'completed'],
        'shipped' => ['completed'],
    ];

    public function __construct(private StockService $stock) {}

    /**
     * @param  array{channel: string, items: array<int, array{variant: Variant, quantity: int, unit_price_cents?: int}>,
     *     customer_id?: ?string, customer?: ?array, paid?: bool, payment_method?: ?string, discount_cents?: int,
     *     shipping_cents?: int, fee_cents?: int, notes?: ?string, external_id?: ?string, placed_at?: Carbon}  $data
     */
    public function place(array $data): Order
    {
        if (empty($data['items'])) {
            throw ValidationException::withMessages(['items' => 'O pedido precisa de pelo menos um item.']);
        }

        return DB::transaction(function () use ($data) {
            $customerId = $data['customer_id'] ?? (! empty($data['customer']['name']) ? Customer::create($data['customer'])->id : null);
            $placedAt = $data['placed_at'] ?? now();
            $paid = (bool) ($data['paid'] ?? false);

            $lines = [];
            $subtotal = 0;
            $cost = 0;
            foreach ($data['items'] as $item) {
                $variant = $item['variant']->loadMissing('product');
                $quantity = max(1, (int) $item['quantity']);
                $price = (int) ($item['unit_price_cents'] ?? $variant->price_cents);
                $unitCost = $this->unitCost($variant);
                $lines[] = [$variant, $quantity, $price, $unitCost];
                $subtotal += $price * $quantity;
                $cost += $unitCost * $quantity;
            }

            $discount = min((int) ($data['discount_cents'] ?? 0), $subtotal);
            $shipping = (int) ($data['shipping_cents'] ?? 0);

            $order = Order::create([
                'number' => $this->nextNumber(),
                'channel' => $data['channel'],
                'external_id' => $data['external_id'] ?? null,
                'customer_id' => $customerId,
                'status' => $paid ? 'paid' : 'awaiting_payment',
                'payment_method' => $data['payment_method'] ?? null,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'shipping_cents' => $shipping,
                'fee_cents' => (int) ($data['fee_cents'] ?? 0),
                'total_cents' => $subtotal - $discount + $shipping,
                'cost_cents' => $cost,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->userId(),
                'placed_at' => $placedAt,
                'paid_at' => $paid ? $placedAt : null,
                'reserved_until' => $paid ? null : $placedAt->copy()->addHours(self::RESERVATION_HOURS),
            ]);

            foreach ($lines as [$variant, $quantity, $price, $unitCost]) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'variant_id' => $variant->id,
                    'product_id' => $variant->product_id,
                    'sku' => $variant->sku,
                    'name' => $variant->fullName(),
                    'quantity' => $quantity,
                    'unit_price_cents' => $price,
                    'unit_cost_cents' => $unitCost,
                    'total_cents' => $price * $quantity,
                ]);
                $paid
                    ? $this->stock->sell($variant, $quantity, $order->id)
                    : $this->stock->reserve($variant, $quantity, $order->id);
            }

            Audit::record('commerce.order.placed', subject: $order, metadata: ['channel' => $order->channel, 'total' => $order->total_cents]);

            return $order;
        });
    }

    /**
     * Editar dados do pedido sem mexer nos itens (para trocar peças: cancelar e lançar de novo).
     * Observação e rastreio sempre; forma de pagamento fora do marketplace; desconto e frete só nos
     * pedidos digitados (balcão, WhatsApp, Instagram): nos outros vêm do canal ou o cliente já viu o Pix.
     *
     * @param  array{notes?: ?string, tracking_code?: ?string, payment_method?: ?string, discount_cents?: ?int, shipping_cents?: ?int}  $data
     */
    public function updateDetails(Order $order, array $data): void
    {
        if ($order->status === 'canceled') {
            throw ValidationException::withMessages(['order' => 'Pedido cancelado não se edita.']);
        }

        $changes = [];
        foreach (['notes', 'tracking_code'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }
        if (! empty($data['payment_method']) && $data['payment_method'] !== 'marketplace' && $order->payment_method !== 'marketplace') {
            $changes['payment_method'] = $data['payment_method'];
        }
        if (in_array($order->channel, Order::MANUAL_CHANNELS, true) && (array_key_exists('discount_cents', $data) || array_key_exists('shipping_cents', $data))) {
            $discount = min(max(0, (int) ($data['discount_cents'] ?? $order->discount_cents)), $order->subtotal_cents);
            $shipping = max(0, (int) ($data['shipping_cents'] ?? $order->shipping_cents));
            $changes += ['discount_cents' => $discount, 'shipping_cents' => $shipping, 'total_cents' => $order->subtotal_cents - $discount + $shipping];
        }

        $before = $order->only(array_keys($changes));
        $order->update($changes);
        Audit::record('commerce.order.edited', subject: $order, metadata: ['before' => $before, 'after' => $order->only(array_keys($changes))]);
    }

    public function markPaid(Order $order, ?string $method = null): void
    {
        $this->guardStatus($order, ['awaiting_payment'], 'confirmar o pagamento');

        DB::transaction(function () use ($order, $method) {
            foreach ($order->items()->with('variant')->get() as $item) {
                $this->stock->sell($item->variant, $item->quantity, $order->id, fromReservation: true);
            }
            $order->update(['status' => 'paid', 'paid_at' => now(), 'reserved_until' => null, 'payment_method' => $method ?? $order->payment_method]);
            Audit::record('commerce.order.paid', subject: $order);
        });
    }

    public function advance(Order $order, string $to, ?string $tracking = null): void
    {
        if (! in_array($to, self::FLOW[$order->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'O pedido não pode ir de "'.$order->statusLabel().'" para "'.(Order::STATUSES[$to] ?? $to).'".']);
        }

        $changes = ['status' => $to];
        if ($to === 'shipped') {
            $changes += ['shipped_at' => now(), 'tracking_code' => $tracking ?: $order->tracking_code];
        }
        if ($to === 'completed') {
            $changes += ['completed_at' => now(), 'shipped_at' => $order->shipped_at ?? now()];
        }

        $from = $order->status;
        $order->update($changes);
        Audit::record('commerce.order.status', subject: $order, metadata: ['from' => $from, 'to' => $to]);
    }

    public function cancel(Order $order, ?string $reason = null): void
    {
        $this->guardStatus($order, ['awaiting_payment', 'paid', 'in_fulfillment', 'shipped'], 'cancelar');

        DB::transaction(function () use ($order, $reason) {
            foreach ($order->items()->with('variant')->get() as $item) {
                if (! $item->variant) {
                    continue;
                }
                $order->status === 'awaiting_payment'
                    ? $this->stock->release($item->variant, $item->quantity, $order->id)
                    : $this->stock->restock($item->variant, $item->quantity, $order->id);
            }

            $notes = trim(($order->notes ? $order->notes."\n" : '').($reason ? 'Cancelado: '.$reason : ''));
            $order->update(['status' => 'canceled', 'canceled_at' => now(), 'reserved_until' => null, 'notes' => $notes ?: null]);
            Audit::record('commerce.order.canceled', subject: $order, metadata: ['reason' => $reason]);
        });
    }

    /** Custo da unidade: o da variante, ou a soma das peças no kit. */
    private function unitCost(Variant $variant): int
    {
        if (! $variant->is_kit) {
            return (int) $variant->cost_cents;
        }

        return (int) KitComponent::with('component')->where('kit_variant_id', $variant->id)->get()
            ->sum(fn (KitComponent $c) => (int) $c->component?->cost_cents * $c->quantity);
    }

    private function nextNumber(): int
    {
        // Trava a loja para dois pedidos simultâneos não pegarem o mesmo número.
        Store::lockForUpdate()->first();

        return ((int) Order::max('number')) + 1;
    }

    private function guardStatus(Order $order, array $allowed, string $action): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Não dá para {$action} um pedido \"{$order->statusLabel()}\"."]);
        }
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? $context->principalId : null;
    }
}
