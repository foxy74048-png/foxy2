<?php

namespace App\Commerce\Catalog;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto: o que o lojista cadastra uma vez. Vende-se a variante (tamanho/cor); produto sem grade tem uma.
 * Kit (type = kit) tem uma variante só, feita de outras variantes (KitComponent), sem estoque próprio.
 */
class Product extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['active' => 'Ativo', 'draft' => 'Rascunho', 'archived' => 'Desativado'];

    protected $table = 'commerce_products';

    protected $fillable = ['category_id', 'type', 'name', 'description', 'brand', 'status', 'option_names', 'specs'];

    protected $attributes = ['type' => 'simple', 'status' => 'active'];

    protected function casts(): array
    {
        return ['option_names' => 'array', 'specs' => 'array'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function isKit(): bool
    {
        return $this->type === 'kit';
    }

    public function hasGrid(): bool
    {
        return ! empty($this->option_names);
    }

    public function spec(string $key): mixed
    {
        return $this->specs[$key] ?? null;
    }
}
