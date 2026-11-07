<?php

namespace App\Commerce\Catalog;

use App\Platform\Files\File;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_product_images';

    protected $fillable = ['product_id', 'file_id', 'position'];

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
