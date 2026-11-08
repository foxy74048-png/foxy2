<?php

namespace App\Commerce\Orders;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item com snapshot (nome, SKU, preço e custo da hora da venda): mudar o catálogo não muda o passado.
 */
class OrderItem extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $table = 'commerce_order_items';

    protected $fillable = ['order_id', 'variant_id', 'product_id', 'sku', 'name', 'quantity', 'unit_price_cents', 'unit_cost_cents', 'total_cents'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
