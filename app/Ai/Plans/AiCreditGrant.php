<?php

namespace App\Ai\Plans;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Créditos extras comprados para um mês (pacotes de 1.000), somados à franquia do plano.
 */
class AiCreditGrant extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'period', 'credits', 'reference'];
}
