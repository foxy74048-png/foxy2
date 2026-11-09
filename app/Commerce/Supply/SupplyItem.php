<?php

namespace App\Commerce\Supply;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da remessa já lançada: a variante, quanto chegou, o custo e (no acerto) quanto voltou e quanto vendeu. */
class SupplyItem extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_supply_items';

    protected $fillable = ['batch_id', 'variant_id', 'description', 'barcode', 'mode', 'quantity', 'document_price_cents', 'cost_cents', 'returned', 'sold'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'document_price_cents' => 'integer', 'cost_cents' => 'integer', 'returned' => 'integer', 'sold' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SupplyBatch::class, 'batch_id');
    }
}
