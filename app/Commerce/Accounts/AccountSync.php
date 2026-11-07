<?php

namespace App\Commerce\Accounts;

use App\Commerce\Stores\Store;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;

/**
 * Mantém a conta da loja no Foxy Admin (docs/08). Mesmo app: grava direto, sem o retrato por HTTP do Studio.
 * Até a fatia de assinatura, toda loja está "em teste" desde a criação.
 */
class AccountSync
{
    public static function sync(Organization $organization): ProductAccount
    {
        $store = Store::withoutGlobalScopes()->where('organization_id', $organization->getKey())->first();
        $owner = Membership::active()->where('organization_id', $organization->getKey())->where('role', 'owner')
            ->with('user')->oldest()->first()?->user;
        $team = Membership::active()->where('organization_id', $organization->getKey())->count();

        return ProductAccount::updateOrCreate(
            ['integration_id' => $organization->integration_id, 'external_ref' => $organization->external_ref],
            [
                'name' => $store?->name ?? $organization->name,
                'plan' => 'Teste grátis',
                'status' => 'trial',
                'mrr' => 0,
                'signed_up_at' => $organization->created_at,
                'trial_ends_at' => $organization->created_at->copy()->addDays(config('commerce.trial_days')),
                'last_activity_at' => now(),
                'contact_name' => $owner?->name,
                'email' => $owner?->email,
                'whatsapp' => $store?->whatsapp,
                'usage' => ['pessoas na equipe' => $team],
                'seen_at' => now(),
            ],
        );
    }
}
