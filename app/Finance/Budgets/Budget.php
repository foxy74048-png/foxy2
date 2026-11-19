<?php

namespace App\Finance\Budgets;

use App\Finance\Categories\Category;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Limite de gasto por categoria (a de grupo inclui as de dentro). month nulo = todo mês. */
class Budget extends Model
{
    use BelongsToOrganization, HasUlids;

    /** A partir de quanto do limite a tela avisa. */
    public const WARN_AT = 0.8;

    protected $table = 'finance_budgets';

    protected $fillable = ['category_id', 'amount_cents', 'month'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
