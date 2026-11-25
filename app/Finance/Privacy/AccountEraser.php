<?php

namespace App\Finance\Privacy;

use App\Finance\Accounts\AccountSync;
use App\Finance\Finance;
use App\Models\User;
use App\Platform\Memberships\Membership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Apagar a conta no Foxy Finanças (fatia 11, LGPD):
 * - carteira em que a pessoa é a única dona: apagada inteira (WalletEraser);
 * - carteira de outra pessoa (ou com outro dono): a pessoa só sai dela;
 * - o WhatsApp ligado e o login de sócio são desfeitos.
 * O login é o mesmo de todos os produtos Foxy: se a pessoa não está em mais nada (Commerce, equipe Foxy),
 * o cadastro é anonimizado (nome e e-mail trocados, senha impossível). Se está, o login continua para o resto.
 */
class AccountEraser
{
    public function __construct(private WalletEraser $wallets) {}

    /** O que vai acontecer, para mostrar antes de confirmar. @return array{delete: list<string>, leave: list<string>} */
    public function preview(User $user): array
    {
        $plan = ['delete' => [], 'leave' => []];
        foreach ($this->financeMemberships($user) as $membership) {
            $name = DB::table('finance_wallets')->where('organization_id', $membership->organization_id)->value('name') ?? $membership->organization->name;
            $plan[$this->isSoleOwner($membership) ? 'delete' : 'leave'][] = $name;
        }

        return $plan;
    }

    /** @return array{deleted: list<string>, left: list<string>, anonymized: bool} */
    public function erase(User $user): array
    {
        $result = ['deleted' => [], 'left' => [], 'anonymized' => false];

        foreach ($this->financeMemberships($user) as $membership) {
            $organization = $membership->organization;
            $name = DB::table('finance_wallets')->where('organization_id', $organization->getKey())->value('name') ?? $organization->name;
            if ($this->isSoleOwner($membership)) {
                $this->wallets->erase($organization);
                $result['deleted'][] = $name;
            } else {
                $membership->delete();
                AccountSync::sync($organization->fresh());
                $result['left'][] = $name;
            }
        }

        DB::table('finance_whatsapp_links')->where('user_id', $user->getKey())->delete();
        DB::table('finance_partners')->where('user_id', $user->getKey())->update(['user_id' => null, 'mirror_organization_id' => null, 'mirror_account_id' => null]);

        $elsewhere = $user->isStaff() || Membership::query()->where('user_id', $user->getKey())->exists();
        if (! $elsewhere) {
            $user->forceFill([
                'name' => 'Conta apagada',
                'email' => 'apagada-'.$user->getKey().'-'.Str::lower(Str::random(8)).'@apagada.invalid',
                'password' => Str::random(64),
                'remember_token' => Str::random(60),
                'email_verified_at' => null,
            ])->save();
            if (\Illuminate\Support\Facades\Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }
            $result['anonymized'] = true;
        }

        return $result;
    }

    private function financeMemberships(User $user)
    {
        $integration = Finance::integration()->getKey();

        return Membership::query()->where('user_id', $user->getKey())->with('organization')->get()
            ->filter(fn (Membership $m) => $m->organization?->integration_id === $integration)->values();
    }

    /** A pessoa é dona e não há outro dono ativo: sem ela, ninguém administraria a carteira. */
    private function isSoleOwner(Membership $membership): bool
    {
        return $membership->role === 'owner' && $membership->status === Membership::ACTIVE && ! Membership::active()
            ->where('organization_id', $membership->organization_id)->where('role', 'owner')
            ->where('user_id', '!=', $membership->user_id)->exists();
    }
}
