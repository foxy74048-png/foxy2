<?php

namespace App\Commerce\Stores;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Commerce;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abre uma loja: organização do Commerce + loja + a pessoa como dona + conta no Foxy Admin.
 */
class OpenStore
{
    public function __construct(private ContextStore $contexts) {}

    public function open(User $owner, string $name, ?string $whatsapp = null): Store
    {
        return DB::transaction(function () use ($owner, $name, $whatsapp) {
            $id = strtolower((string) Str::ulid());
            $organization = Organization::forceCreate([
                'id' => $id,
                'integration_id' => Commerce::integration()->getKey(),
                'name' => $name,
                'external_ref' => 'loja-'.$id,
            ]);

            return $this->contexts->run(ExecutionContext::forUser($organization, $owner->getKey()), function () use ($organization, $owner, $name, $whatsapp) {
                $store = Store::create(['slug' => Store::availableSlug($name), 'name' => $name, 'whatsapp' => $whatsapp]);
                Membership::create(['organization_id' => $organization->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner']);
                Subscription::create([
                    'organization_id' => $organization->getKey(),
                    'product' => Commerce::SLUG,
                    'status' => 'trialing',
                    'trial_ends_at' => now()->addDays(config('commerce.trial_days')),
                ]);
                Audit::record('commerce.store.opened', subject: $store);
                AccountSync::sync($organization);

                return $store;
            });
        });
    }
}
