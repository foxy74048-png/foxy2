<?php

namespace App\Platform\Organizations;

use App\Platform\Context\ContextStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para todo model de dado de negócio: grava e filtra pela organização do contexto.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            if ($model->organization_id) {
                return;
            }

            $store = app(ContextStore::class);

            if (! $store->has()) {
                throw new LogicException('Dado de organização criado sem ExecutionContext.');
            }

            $model->organization_id = $store->get()->organization->getKey();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
