<?php

namespace App\Platform\Integrations;

use App\Platform\Context\ContextStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra pela integração do ExecutionContext. Sem contexto, não devolve nada.
 */
class IntegrationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $store = app(ContextStore::class);

        if (! $store->has()) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('integration_id'), $store->get()->integration->getKey());
    }
}
