<?php

namespace App\Commerce\Accounts;

use App\Commerce\Billing\Plans;
use App\Commerce\Stores\Store;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;

/**
 * Mantém a conta da loja no Foxy Admin (docs/08). Mesmo app: grava direto, sem o retrato por HTTP do Studio.
 * Chamado quando a loja, a equipe ou a assinatura mudam, e de hora em hora (o teste vence sozinho).
 */
class AccountSync
{
    public static function sync(Organization $organization): ProductAccount
    {
        $store = Store::withoutGlobalScopes()->where('organization_id', $organization->getKey())->first();
        $owner = Membership::active()->where('organization_id', $organization->getKey())->where('role', 'owner')
            ->with('user')->oldest()->first()?->user;
        $team = Membership::active()->where('organization_id', $organization->getKey())->count();
        $subscription = Plans::subscription($organization);

        [$status, $plan, $mrr] = match (true) {
            $organization->status === 'suspended' => ['suspended', Plans::name($subscription?->plan), 0],
            $subscription?->isFounder() === true => ['founder', 'Parceiro Fundador', 0],
            $subscription?->awaitingFirstCharge() === true => ['trial', Plans::name($subscription->plan).' (assinado, cobra quando o teste acabar)', 0],
            $subscription?->isPaying() === true => ['active', Plans::name($subscription->plan), config("commerce.plans.{$subscription->plan}.price_cents", 0) / 100],
            $subscription?->inTrial() === true => ['trial', 'Teste grátis', 0],
            $subscription?->stripe_subscription_id !== null => ['canceled', Plans::name($subscription->plan), 0],
            default => ['expired', 'Teste grátis', 0],
        };

        $problems = [];
        if ($subscription?->isPastDue()) {
            $problems[] = ['key' => 'pagamento', 'label' => 'Pagamento atrasado', 'detail' => 'O Stripe não conseguiu cobrar o cartão e está tentando de novo.'];
        }

        return ProductAccount::updateOrCreate(
            ['integration_id' => $organization->integration_id, 'external_ref' => $organization->external_ref],
            [
                'name' => $store?->name ?? $organization->name,
                'plan' => $plan,
                'status' => $status,
                'mrr' => $mrr,
                'signed_up_at' => $organization->created_at,
                'trial_ends_at' => $subscription?->trial_ends_at,
                'contact_name' => $owner?->name,
                'email' => $owner?->email,
                'whatsapp' => $store?->whatsapp,
                'usage' => ['pessoas na equipe' => $team],
                'problems' => $problems,
                'seen_at' => now(),
            ],
        );
    }
}
