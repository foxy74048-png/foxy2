<?php

namespace App\Commerce\Consignment;

use App\Commerce\Catalog\Money;
use App\Commerce\Catalog\Variant;
use App\Commerce\Finance\FinanceService;
use App\Commerce\Inventory\StockService;
use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Commerce\Orders\OrderService;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Maleta/sacolinha (consignação enviada): abrir reserva as peças; acrescentar mais peças; acertar libera o
 * que voltou e vende o que ficou (pedido no canal "consignação", com a comissão da revendedora como
 * desconto), recebido na hora ou em parcelas a receber no nome da pessoa.
 */
class ConsignmentService
{
    public function __construct(private StockService $stock, private OrderService $orders, private FinanceService $finance) {}

    /**
     * @param  array<int, array{variant: Variant, quantity: int}>  $items
     */
    public function open(Customer $customer, array $data, array $items): Consignment
    {
        return DB::transaction(function () use ($customer, $data, $items) {
            $kind = ($data['kind'] ?? 'cliente') === 'revendedora' ? 'revendedora' : 'cliente';
            $consignment = Consignment::create([
                'number' => (int) Consignment::max('number') + 1,
                'customer_id' => $customer->id,
                'kind' => $kind,
                'commission_percent' => $kind === 'revendedora' ? max(0, min(100, (float) ($data['commission_percent'] ?? 0))) : 0,
                'sent_on' => $data['sent_on'] ?? today()->toDateString(),
                'due_on' => $data['due_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $this->userId(),
            ]);
            $consignment->setRelation('customer', $customer);
            $this->add($consignment, $items);
            Audit::record('commerce.consignment.opened', subject: $consignment, metadata: ['pieces' => $consignment->items()->sum('quantity')]);

            return $consignment;
        });
    }

    /**
     * Acrescenta peças (na abertura ou depois, numa reposição). Só o disponível: a peça reservada aqui não
     * aparece para outro pedido nem na loja.
     *
     * @param  array<int, array{variant: Variant, quantity: int}>  $items
     */
    public function add(Consignment $consignment, array $items): void
    {
        if ($consignment->status !== 'aberta') {
            throw ValidationException::withMessages(['items' => 'Esta maleta já foi acertada.']);
        }
        $items = array_values(array_filter($items, fn ($i) => (int) $i['quantity'] > 0));
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Escolha pelo menos uma peça.']);
        }

        DB::transaction(function () use ($consignment, $items) {
            $wanted = [];
            foreach ($items as $item) {
                $wanted[$item['variant']->id] = ($wanted[$item['variant']->id] ?? 0) + (int) $item['quantity'];
            }
            $available = $this->stock->availability(collect($items)->pluck('variant')->unique('id'));
            foreach ($items as $item) {
                $variant = $item['variant']->loadMissing('product');
                if ($wanted[$variant->id] > ($available[$variant->id] ?? 0)) {
                    throw ValidationException::withMessages(['items' => $variant->fullName().': só tem '.max(0, $available[$variant->id] ?? 0).' disponível.']);
                }
            }

            $reason = $consignment->label();
            foreach ($wanted as $variantId => $quantity) {
                $variant = collect($items)->first(fn ($i) => $i['variant']->id === $variantId)['variant'];
                $line = $consignment->items()->where('variant_id', $variantId)->first();
                $line
                    ? $line->increment('quantity', $quantity)
                    : $consignment->items()->create(['variant_id' => $variantId, 'name' => Str::limit($variant->fullName(), 200, ''), 'quantity' => $quantity, 'price_cents' => (int) $variant->price_cents]);
                $this->stock->reserve($variant, $quantity, null, $reason);
            }
        });
    }

    /**
     * Acerto. $returns = item => quantas voltaram. $payment = ['mode' => 'pago'|'receber', 'method'?, 'installments'?, 'first_due'?].
     * Devolve o pedido gerado (null quando tudo voltou).
     */
    public function settle(Consignment $consignment, array $returns, array $payment = []): ?Order
    {
        if ($consignment->status !== 'aberta') {
            throw ValidationException::withMessages(['status' => 'Esta maleta já foi acertada.']);
        }

        return DB::transaction(function () use ($consignment, $returns, $payment) {
            $consignment->load('items.variant', 'customer');
            $sold = [];
            foreach ($consignment->items as $item) {
                $back = max(0, min($item->quantity, (int) ($returns[$item->id] ?? $item->quantity)));
                $item->update(['returned' => $back]);
                if ($item->variant) {
                    $this->stock->release($item->variant, $item->quantity, null, 'Acerto da '.$consignment->label());
                }
                if ($item->quantity - $back > 0 && $item->variant) {
                    $sold[] = ['variant' => $item->variant, 'quantity' => $item->quantity - $back, 'unit_price_cents' => $item->price_cents];
                }
            }

            $order = null;
            if ($sold !== []) {
                $gross = (int) collect($sold)->sum(fn ($s) => $s['unit_price_cents'] * $s['quantity']);
                $commission = (int) round($gross * $consignment->commission_percent / 100);
                $credit = ($payment['mode'] ?? 'pago') === 'receber';
                $order = $this->orders->place([
                    'channel' => 'consignacao',
                    'customer_id' => $consignment->customer_id,
                    'items' => $sold,
                    'discount_cents' => $commission,
                    'paid' => true,
                    'payment_method' => $credit ? 'crediario' : ($payment['method'] ?? 'pix'),
                    'notes' => trim('Acerto da '.$consignment->label().($commission > 0 ? "\nComissão da revendedora (".rtrim(rtrim(number_format($consignment->commission_percent, 2, ',', ''), '0'), ',').'%): '.Money::format($commission) : '')),
                ]);
                if ($credit && $order->total_cents > 0) {
                    $this->finance->installments(['description' => 'Pedido #'.$order->number.' (maleta #'.$consignment->number.')'], $order->total_cents,
                        (int) ($payment['installments'] ?? 1), Carbon::parse($payment['first_due'] ?? today()->addMonth()), $order);
                }
            }

            $consignment->update(['status' => 'acertada', 'settled_at' => now(), 'order_id' => $order?->id]);
            Audit::record('commerce.consignment.settled', subject: $consignment, metadata: ['order' => $order?->id]);

            return $order;
        });
    }

    private function userId(): ?string
    {
        $context = ExecutionContext::current();

        return $context->principalType === ExecutionContext::PRINCIPAL_USER ? (string) $context->principalId : null;
    }
}
