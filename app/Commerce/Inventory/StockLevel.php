<?php

namespace App\Commerce\Inventory;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo de uma variante. Só StockService altera. Disponível = físico − reservado.
 */
class StockLevel extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_stock_levels';

    protected $fillable = ['variant_id', 'on_hand', 'reserved', 'min_stock'];

    protected $attributes = ['on_hand' => 0, 'reserved' => 0];

    protected function casts(): array
    {
        return ['on_hand' => 'integer', 'reserved' => 'integer', 'min_stock' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function available(): int
    {
        return $this->on_hand - $this->reserved;
    }
}
