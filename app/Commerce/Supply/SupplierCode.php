<?php

namespace App\Commerce\Supply;

use App\Commerce\Catalog\Variant;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Memória de uma peça da fornecedora: o código (de barras, do fornecedor ou a descrição) → a variação da loja. */
class SupplierCode extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_supplier_codes';

    protected $fillable = ['supplier_id', 'code', 'variant_id'];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }
}
