<?php

namespace App\Finance\Categories;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Categoria de gasto ou de recebimento, em 2 níveis (Moradia › Aluguel). A lista pronta vem de
 * DefaultCategories (system_key); a pessoa cria, renomeia, esconde e junta.
 */
class Category extends Model
{
    use BelongsToOrganization, HasUlids;

    public const KINDS = ['gasto' => 'Gasto', 'recebimento' => 'Recebimento'];

    protected $table = 'finance_categories';

    protected $fillable = ['parent_id', 'kind', 'name', 'system_key', 'hidden_at'];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeVisible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    public function fullName(): string
    {
        return $this->parent ? $this->parent->name.' › '.$this->name : $this->name;
    }

    /**
     * Para os selects: [tipo => [grupo => [id => nome]]], só as visíveis, em ordem alfabética.
     *
     * @return array<string, array<string, array<string, string>>>
     */
    public static function options(?string $include = null): array
    {
        $all = self::query()->where(fn ($q) => $q->whereNull('hidden_at')->when($include, fn ($q) => $q->orWhere('id', $include)))
            ->orderBy('name')->get();
        $roots = $all->whereNull('parent_id');
        $out = [];
        foreach (self::KINDS as $kind => $label) {
            foreach ($roots->where('kind', $kind) as $root) {
                $out[$kind][$root->name] = [$root->id => $root->name.' (geral)']
                    + $all->where('parent_id', $root->id)->mapWithKeys(fn ($c) => [$c->id => $c->name])->all();
            }
        }

        return $out;
    }

    /** Árvore para a tela de categorias: raízes com os filhos. */
    public static function tree(string $kind): Collection
    {
        $all = self::query()->where('kind', $kind)->orderBy('name')->get();

        return $all->whereNull('parent_id')->values()->map(fn ($root) => ['category' => $root, 'children' => $all->where('parent_id', $root->id)->values()]);
    }
}
