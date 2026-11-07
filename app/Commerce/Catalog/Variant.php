<?php

namespace App\Commerce\Catalog;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unidade vendável e estocável (camiseta P azul). SKU único na loja.
 */
class Variant extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_variants';

    protected $fillable = ['product_id', 'sku', 'options', 'price_cents', 'cost_cents', 'gtin', 'is_kit', 'status', 'position'];

    protected $attributes = ['status' => 'active', 'is_kit' => false];

    protected function casts(): array
    {
        return ['options' => 'array', 'is_kit' => 'boolean', 'price_cents' => 'integer', 'cost_cents' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Peças do kit (só em variante de kit). */
    public function components(): HasMany
    {
        return $this->hasMany(KitComponent::class, 'kit_variant_id');
    }

    /** "P / Azul" (vazio sem grade). */
    public function label(): string
    {
        return implode(' / ', array_values($this->options ?? []));
    }

    /** "Camiseta Básica — P / Azul" */
    public function fullName(): string
    {
        $label = $this->label();

        return $this->product->name.($label !== '' ? ' — '.$label : '');
    }
}
