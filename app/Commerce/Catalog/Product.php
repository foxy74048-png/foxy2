<?php

namespace App\Commerce\Catalog;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Produto: o que o lojista cadastra uma vez. Vende-se a variante (tamanho/cor); produto sem grade tem uma.
 * Kit (type = kit) tem uma variante só, feita de outras variantes (KitComponent), sem estoque próprio.
 */
class Product extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['active' => 'Ativo', 'draft' => 'Rascunho', 'archived' => 'Desativado'];

    protected $table = 'commerce_products';

    protected $fillable = ['category_id', 'type', 'name', 'description', 'brand', 'status', 'option_names', 'specs', 'kit_set'];

    protected $attributes = ['type' => 'simple', 'status' => 'active'];

    protected function casts(): array
    {
        return ['option_names' => 'array', 'specs' => 'array', 'kit_set' => 'array'];
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

    /**
     * Fotos de uma variação: primeiro as da cor/opção dela, depois as do produto todo.
     *
     * @return Collection<int, ProductImage>
     */
    public function imagesFor(?array $options)
    {
        return $this->images->filter(fn (ProductImage $i) => $i->appliesTo($options))
            ->sortBy(fn (ProductImage $i) => ($i->isGeneral() ? 100000 : 0) + $i->position)->values();
    }

    /** Valores que dá para escolher numa foto: ["Cor" => ["Preto", "Nude"], "Tamanho" => [...]] a partir das variantes. */
    public function imageOptions(): array
    {
        $result = [];
        foreach ($this->option_names ?? [] as $name) {
            $result[$name] = $this->variants->map(fn (Variant $v) => $v->options[$name] ?? null)->filter()->unique()->values()->all();
        }

        return $result;
    }

    public function isKit(): bool
    {
        return $this->type === 'kit';
    }

    /** Conjunto: kit em que se escolhe o tamanho de cada peça (uma variante por combinação). */
    public function isSet(): bool
    {
        return $this->isKit() && ! empty($this->kit_set);
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
