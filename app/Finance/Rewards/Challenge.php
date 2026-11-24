<?php

namespace App\Finance\Rewards;

use App\Finance\Categories\Category;
use App\Finance\Goals\Goal;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Desafio do mês aceito pela carteira:
 * - categoria: gastar até target_cents na categoria (o grupo, com as subcategorias);
 * - meta: guardar target_cents na meta dentro do mês;
 * - gastinhos: no máximo target_count compras de até R$ 30.
 * No fim do mês vira "ganhou" (pontos) ou "perdeu"; result guarda o que aconteceu (centavos ou quantidade).
 */
class Challenge extends Model
{
    use BelongsToOrganization, HasUlids;

    public const CATEGORY = 'categoria';

    public const GOAL = 'meta';

    public const SMALL = 'gastinhos';

    public const ACTIVE = 'ativo';

    public const WON = 'ganhou';

    public const LOST = 'perdeu';

    protected $table = 'finance_challenges';

    protected $fillable = ['month', 'kind', 'category_id', 'goal_id', 'target_cents', 'target_count', 'label', 'status', 'result', 'accepted_by'];

    protected function casts(): array
    {
        return ['target_cents' => 'integer', 'target_count' => 'integer', 'result' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
