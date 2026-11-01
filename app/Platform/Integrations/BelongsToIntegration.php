<?php

namespace App\Platform\Integrations;

use App\Platform\Context\ContextStore;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Para dados do sistema (comuns a todos os seus clientes): grava e filtra pela integração do contexto.
 */
trait BelongsToIntegration
{
    public static function bootBelongsToIntegration(): void
    {
        static::addGlobalScope(new IntegrationScope);

        static::creating(function ($model) {
            if ($model->integration_id) {
                return;
            }

            $store = app(ContextStore::class);

            if (! $store->has()) {
                throw new LogicException('Dado de integração criado sem ExecutionContext.');
            }

            $model->integration_id = $store->get()->integration->getKey();
        });
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
