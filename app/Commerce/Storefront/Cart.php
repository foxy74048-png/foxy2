<?php

namespace App\Commerce\Storefront;

use App\Commerce\Catalog\Variant;
use App\Commerce\Inventory\StockService;
use App\Commerce\Stores\Store;
use Illuminate\Support\Collection;

/**
 * Carrinho da loja própria, na sessão do comprador (um por loja). Só variantes ativas da loja do contexto.
 */
class Cart
{
    public function __construct(private StockService $stock) {}

    private function key(Store $store): string
    {
        return 'loja.'.$store->id.'.cart';
    }

    /** @return array<string, int> variant_id => quantidade */
    public function raw(Store $store): array
    {
        return session($this->key($store), []);
    }

    public function count(Store $store): int
    {
        return array_sum($this->raw($store));
    }

    public function put(Store $store, string $variantId, int $quantity): void
    {
        $items = $this->raw($store);
        $quantity > 0 ? $items[$variantId] = min($quantity, 99) : $items = array_diff_key($items, [$variantId => true]);
        session([$this->key($store) => $items]);
    }

    public function clear(Store $store): void
    {
        session()->forget($this->key($store));
    }

    /**
     * Linhas do carrinho com preço e disponível agora (o que sumiu do catálogo cai fora).
     *
     * @return Collection<int, array{variant: Variant, quantity: int, available: int, total_cents: int}>
     */
    public function lines(Store $store): Collection
    {
        $raw = $this->raw($store);
        $variants = Variant::with('product.images.file')->whereIn('id', array_keys($raw))->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', 'active'))->get();
        $available = $this->stock->availability($variants);

        return $variants->map(fn (Variant $v) => [
            'variant' => $v, 'quantity' => $raw[$v->id], 'available' => max(0, $available[$v->id] ?? 0), 'total_cents' => $v->price_cents * $raw[$v->id],
        ])->values();
    }
}
