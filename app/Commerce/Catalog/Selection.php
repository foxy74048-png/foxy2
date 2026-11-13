<?php

namespace App\Commerce\Catalog;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Seleção do catálogo: algumas peças escolhidas pela lojista num link só delas, para mandar às clientes. O link
 * mostra o estoque de agora (o que esgotou some, se only_in_stock) e cada peça abre a página normal da loja.
 */
class Selection extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'commerce_selections';

    protected $fillable = ['title', 'product_ids', 'only_in_stock', 'active'];

    protected function casts(): array
    {
        return ['product_ids' => 'array', 'only_in_stock' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Selection $s) => $s->token ??= Str::random(32));
    }

    /** As peças da seleção que ainda estão ativas, na ordem escolhida. */
    public function products(bool $withRelations = true): Collection
    {
        $ids = $this->product_ids ?? [];
        $products = Product::query()
            ->when($withRelations, fn ($q) => $q->with(['images.file', 'variants' => fn ($v) => $v->where('status', 'active')]))
            ->whereIn('id', $ids)->where('status', 'active')->get();

        return $products->sortBy(fn (Product $p) => array_search($p->id, $ids, true))->values();
    }
}
