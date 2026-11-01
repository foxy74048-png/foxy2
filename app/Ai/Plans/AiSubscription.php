<?php

namespace App\Ai\Plans;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Plano de IA de um cliente, definido pelo sistema (integração) via API.
 */
class AiSubscription extends Model
{
    use BelongsToOrganization;

    protected $fillable = ['organization_id', 'plan', 'status'];

    protected $attributes = ['status' => 'active'];
}
