<?php

namespace App\Commerce\Consignment;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Peça da maleta: quanto foi, a que preço e (no acerto) quanto voltou. */
class ConsignmentItem extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_consignment_items';

    protected $fillable = ['consignment_id', 'variant_id', 'name', 'quantity', 'price_cents', 'returned'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'price_cents' => 'integer', 'returned' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function sold(): ?int
    {
        return $this->returned === null ? null : $this->quantity - $this->returned;
    }
}
