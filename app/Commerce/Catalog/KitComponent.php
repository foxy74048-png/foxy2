<?php

namespace App\Commerce\Catalog;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitComponent extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_kit_components';

    protected $fillable = ['kit_variant_id', 'component_variant_id', 'quantity'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'component_variant_id');
    }
}
