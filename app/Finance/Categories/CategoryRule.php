<?php

namespace App\Finance\Categories;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Sempre que a descrição tiver <pattern>, é <categoria>." O padrão fica normalizado (Categorizer::normalize)
 * e casa por palavra inteira. Criada pela pessoa (ao corrigir uma categoria) ou, na fatia 8, pela raposa.
 */
class CategoryRule extends Model
{
    use BelongsToOrganization, HasUlids;

    public const ORIGINS = ['pessoa' => 'Você', 'raposa' => 'Raposa'];

    protected $table = 'finance_category_rules';

    protected $fillable = ['pattern', 'kind', 'category_id', 'origin', 'hits'];

    protected function casts(): array
    {
        return ['hits' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
