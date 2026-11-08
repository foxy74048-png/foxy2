<?php

namespace App\Commerce\Channels;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingVariant extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $table = 'commerce_listing_variants';

    protected $fillable = ['listing_id', 'variant_id', 'external_variation_id', 'pushed_quantity'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
