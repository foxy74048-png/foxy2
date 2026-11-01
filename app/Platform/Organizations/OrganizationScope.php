<?php

namespace App\Platform\Organizations;

use App\Platform\Context\ContextStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra pela organização do ExecutionContext. Sem contexto (ou contexto só de sistema), não devolve nada:
 * rotinas da Foxy que cruzam organizações precisam remover o escopo explicitamente.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $store = app(ContextStore::class);
        $organization = $store->has() ? $store->get()->organization : null;

        if (! $organization) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('organization_id'), $organization->getKey());
    }
}
