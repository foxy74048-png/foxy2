<?php

namespace App\Commerce\Inventory;

use App\Commerce\Catalog\KitComponent;
use App\Commerce\Catalog\Variant;
use App\Platform\Context\ExecutionContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Única porta para mexer no estoque (docs/09, seção 5). Trava a linha do saldo, grava o movimento e, depois
 * da transação, avisa quem precisa saber (StockChanged). Kit não tem saldo: o movimento vai para cada peça.
 */
class StockService
{
    /** @var array<string, string[]> organização => variantes mudadas, para avisar uma vez só no fim */
    private array $pending = [];

    public function receive(Variant $variant, int $quantity, ?string $reason = null): void
    {
        $this->guardNotKit($variant, 'dar entrada');
        $this->apply($variant, 'entrada', $this->positive($quantity), 0, null, $reason);
    }

    public function remove(Variant $variant, int $quantity, ?string $reason = null): void
    {
        $this->guardNotKit($variant, 'dar saída');
        $this->apply($variant, 'saida', -$this->positive($quantity), 0, null, $reason);
    }

    /** Contagem física: o saldo passa a ser o número contado; o movimento guarda a diferença. */
    public function count(Variant $variant, int $counted, ?string $reason = null): void
    {
        $this->guardNotKit($variant, 'contar');
        if ($counted < 0) {
            throw ValidationException::withMessages(['quantity' => 'A contagem não pode ser negativa.']);
        }

        DB::transaction(function () use ($variant, $counted, $reason) {
            $level = $this->lockedLevel($variant);
            $this->apply($variant, 'ajuste', $counted - $level->on_hand, 0, null, $reason);
        });
    }

    /** Venda: baixa o físico; se vinha de reserva (loja própria), solta a reserva junto. */
    public function sell(Variant $variant, int $quantity, ?string $orderId = null, bool $fromReservation = false): void
    {
        $quantity = $this->positive($quantity);
        $this->apply($variant, 'venda', -$quantity, $fromReservation ? -$quantity : 0, $orderId);
    }

    /** Devolve ao estoque (pedido cancelado depois da baixa, ou devolução do cliente). */
    public function restock(Variant $variant, int $quantity, ?string $orderId = null, string $type = 'cancelamento'): void
    {
        $this->apply($variant, in_array($type, ['cancelamento', 'devolucao'], true) ? $type : 'cancelamento', $this->positive($quantity), 0, $orderId);
    }

    public function reserve(Variant $variant, int $quantity, ?string $orderId = null): void
    {
        $this->apply($variant, 'reserva', 0, $this->positive($quantity), $orderId);
    }

    public function release(Variant $variant, int $quantity, ?string $orderId = null): void
    {
        $this->apply($variant, 'liberacao', 0, -$this->positive($quantity), $orderId);
    }

    public function setMinimum(Variant $variant, ?int $minimum): void
    {
        $this->guardNotKit($variant, 'definir estoque mínimo');
        StockLevel::firstOrCreate(['variant_id' => $variant->id])->update(['min_stock' => $minimum]);
    }

    /**
     * Disponível de cada variante (kit = quantos kits as peças permitem montar, nunca negativo).
     *
     * @param  Collection<int, Variant>  $variants
     * @return array<string, int>
     */
    public function availability(Collection $variants): array
    {
        $kitIds = $variants->where('is_kit', true)->pluck('id');
        $components = KitComponent::whereIn('kit_variant_id', $kitIds)->get()->groupBy('kit_variant_id');
        $ids = $variants->pluck('id')->merge($components->flatten()->pluck('component_variant_id'))->unique();
        $levels = StockLevel::whereIn('variant_id', $ids)->get()->keyBy('variant_id');
        $available = fn (string $id) => $levels->get($id)?->available() ?? 0;

        $result = [];
        foreach ($variants as $variant) {
            if (! $variant->is_kit) {
                $result[$variant->id] = $available($variant->id);

                continue;
            }

            $pieces = $components->get($variant->id, collect());
            $result[$variant->id] = $pieces->isEmpty() ? 0 : max(0, (int) $pieces->min(
                fn (KitComponent $c) => intdiv(max(0, $available($c->component_variant_id)), max(1, $c->quantity))
            ));
        }

        return $result;
    }

    private function apply(Variant $variant, string $type, int $onHandChange, int $reservedChange, ?string $orderId, ?string $reason = null): void
    {
        if ($variant->is_kit) {
            $pieces = KitComponent::with('component')->where('kit_variant_id', $variant->id)->get();
            if ($pieces->isEmpty()) {
                throw ValidationException::withMessages(['quantity' => "O kit {$variant->sku} não tem peças."]);
            }

            DB::transaction(function () use ($pieces, $type, $onHandChange, $reservedChange, $orderId, $variant) {
                foreach ($pieces as $piece) {
                    $this->apply($piece->component, $type, $onHandChange * $piece->quantity, $reservedChange * $piece->quantity, $orderId, "kit {$variant->sku}");
                }
            });

            return;
        }

        $context = ExecutionContext::current();

        DB::transaction(function () use ($variant, $type, $onHandChange, $reservedChange, $orderId, $reason, $context) {
            $level = $this->lockedLevel($variant);
            $level->on_hand += $onHandChange;
            $level->reserved = max(0, $level->reserved + $reservedChange);
            $level->save();

            StockMovement::create([
                'variant_id' => $variant->id,
                'type' => $type,
                'quantity' => $onHandChange,
                'reserved_change' => $reservedChange,
                'on_hand_after' => $level->on_hand,
                'order_id' => $orderId,
                'reason' => $reason ? mb_substr($reason, 0, 255) : null,
                'user_id' => $context->principalType === ExecutionContext::PRINCIPAL_USER ? $context->principalId : null,
            ]);
        });

        $this->notifyAfterCommit($variant);
    }

    private function lockedLevel(Variant $variant): StockLevel
    {
        StockLevel::firstOrCreate(['variant_id' => $variant->id]);

        return StockLevel::where('variant_id', $variant->id)->lockForUpdate()->firstOrFail();
    }

    /** Junta as variantes mudadas e avisa uma vez, com os kits que usam essas peças, quando tudo gravar. */
    private function notifyAfterCommit(Variant $variant): void
    {
        $organizationId = $variant->organization_id;
        $first = ! isset($this->pending[$organizationId]);
        $this->pending[$organizationId][] = $variant->id;

        if ($first) {
            DB::afterCommit(function () use ($organizationId) {
                $ids = array_values(array_unique($this->pending[$organizationId] ?? []));
                unset($this->pending[$organizationId]);
                $kits = KitComponent::whereIn('component_variant_id', $ids)->pluck('kit_variant_id')->all();
                StockChanged::dispatch($organizationId, array_values(array_unique([...$ids, ...$kits])));
            });
        }
    }

    private function guardNotKit(Variant $variant, string $action): void
    {
        if ($variant->is_kit) {
            throw ValidationException::withMessages(['quantity' => "Não dá para {$action} num kit: o estoque do kit vem das peças."]);
        }
    }

    private function positive(int $quantity): int
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'A quantidade precisa ser maior que zero.']);
        }

        return $quantity;
    }
}
