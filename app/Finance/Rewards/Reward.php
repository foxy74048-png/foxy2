<?php

namespace App\Finance\Rewards;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Um ponto ganho pela carteira: mês no azul, orçamento respeitado, meta no ritmo, fatura em dia, desafio vencido
 * (kind "pontos") ou uma conquista (kind "conquista"). A chave é única por carteira: recalcular não duplica.
 * kind "marca" = mês já avaliado (sem pontos, não aparece).
 */
class Reward extends Model
{
    use BelongsToOrganization, HasUlids;

    public const POINTS = 'pontos';

    public const BADGE = 'conquista';

    public const MARKER = 'marca';

    protected $table = 'finance_rewards';

    protected $fillable = ['key', 'kind', 'points', 'label', 'month', 'user_id'];

    protected function casts(): array
    {
        return ['points' => 'integer'];
    }
}
