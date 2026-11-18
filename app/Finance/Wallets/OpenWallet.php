<?php

namespace App\Finance\Wallets;

use App\Finance\Accounts\AccountSync;
use App\Finance\Finance;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Abre uma carteira: organização do Finanças + carteira + a pessoa como dona + conta no Foxy Admin.
 */
class OpenWallet
{
    public function __construct(private ContextStore $contexts) {}

    public function open(User $owner, string $name, string $kind = Wallet::PERSONAL, bool $isMei = false): Wallet
    {
        return DB::transaction(function () use ($owner, $name, $kind, $isMei) {
            $id = strtolower((string) Str::ulid());
            $organization = Organization::forceCreate([
                'id' => $id,
                'integration_id' => Finance::integration()->getKey(),
                'name' => $name,
                'external_ref' => 'carteira-'.$id,
            ]);

            return $this->contexts->run(ExecutionContext::forUser($organization, $owner->getKey()), function () use ($organization, $owner, $name, $kind, $isMei) {
                $wallet = Wallet::create(['name' => $name, 'kind' => $kind, 'is_mei' => $kind === Wallet::BUSINESS && $isMei]);
                Membership::create(['organization_id' => $organization->getKey(), 'user_id' => $owner->getKey(), 'role' => 'owner']);
                Audit::record('finance.wallet.opened', subject: $wallet, metadata: ['kind' => $kind]);
                AccountSync::sync($organization);

                return $wallet;
            });
        });
    }

    /** Carteiras de que a pessoa é dona (para o limite de config finance.max_owned_wallets). */
    public static function ownedBy(User $user): int
    {
        $integrationId = Finance::integration()->getKey();

        return Membership::active()->where('user_id', $user->getKey())->where('role', 'owner')
            ->whereHas('organization', fn ($q) => $q->where('integration_id', $integrationId))->count();
    }
}
