<?php

namespace App\Finance\Accounts;

use App\Finance\Transactions\Transaction;
use App\Finance\Wallets\Wallet;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;
use App\Platform\Products\ProductAccount;

/**
 * Mantém a conta da carteira no Foxy Admin (docs/08). Mesmo app: grava direto. Só nome, plano, contato do
 * dono e contagens — nunca valores nem lançamentos (docs/12, seção 10). Planos pagos chegam na fatia 10;
 * até lá toda carteira está no plano Grátis.
 */
class AccountSync
{
    public static function sync(Organization $organization): ProductAccount
    {
        $wallet = Wallet::withoutGlobalScopes()->where('organization_id', $organization->getKey())->first();
        $owner = Membership::active()->where('organization_id', $organization->getKey())->where('role', 'owner')
            ->with('user')->oldest()->first()?->user;
        $people = Membership::active()->where('organization_id', $organization->getKey())->count();
        $accounts = Account::withoutGlobalScopes()->where('organization_id', $organization->getKey())->whereNull('archived_at')->count();
        $transactions = Transaction::withoutGlobalScopes()->where('organization_id', $organization->getKey())->whereNull('deleted_at')->count();
        $last = Transaction::withoutGlobalScopes()->where('organization_id', $organization->getKey())->max('created_at');
        // 1º lançamento = quando a carteira ativou (métricas do Foxy Admin).
        $first = Transaction::withoutGlobalScopes()->where('organization_id', $organization->getKey())->min('created_at');

        return ProductAccount::updateOrCreate(
            ['integration_id' => $organization->integration_id, 'external_ref' => $organization->external_ref],
            [
                'name' => $wallet?->name ?? $organization->name,
                'plan' => 'Grátis',
                // Carteira apagada pela pessoa (fatia 11): fica no Admin como cancelada, sem contato.
                'status' => match ($organization->status) { 'suspended' => 'suspended', \App\Finance\Privacy\WalletEraser::DELETED => 'canceled', default => 'free' },
                'mrr' => 0,
                'signed_up_at' => $organization->created_at,
                'contact_name' => $owner?->name,
                'email' => $owner?->email,
                'usage' => ['tipo' => $wallet?->kindLabel(), 'pessoas na carteira' => $people, 'contas' => $accounts, 'lançamentos' => $transactions],
                'last_activity_at' => $last,
                'activated_at' => $first,
                'problems' => [],
                'seen_at' => now(),
            ],
        );
    }
}
