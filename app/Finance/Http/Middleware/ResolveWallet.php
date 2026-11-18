<?php

namespace App\Finance\Http\Middleware;

use App\Finance\Finance;
use App\Finance\Wallets\Wallet;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * App do Finanças: acha a carteira em que a pessoa está (vínculo ativo; a escolhida na sessão, ou a única,
 * ou a pessoal dela) e monta o ExecutionContext. Daqui para frente, tudo o que tem BelongsToOrganization já
 * filtra por esta carteira.
 */
class ResolveWallet
{
    public const SESSION_KEY = 'finance.organization_id';

    public function __construct(private ContextStore $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $memberships = self::membershipsOf($user->getKey());

        if ($memberships->isEmpty()) {
            return redirect()->route('finance.wallets.new');
        }

        $membership = $memberships->firstWhere('organization_id', $request->session()->get(self::SESSION_KEY))
            ?? ($memberships->count() === 1 ? $memberships->first() : self::defaultOf($memberships));

        if (! $membership) {
            return redirect()->route('finance.wallets.choose');
        }

        $request->session()->put(self::SESSION_KEY, $membership->organization_id);
        $this->contexts->set(ExecutionContext::forUser($membership->organization, $user->getKey()));

        $wallet = Wallet::firstOrFail();
        $request->attributes->set('finance.membership', $membership);
        $request->attributes->set('finance.wallet', $wallet);
        view()->share([
            'currentWallet' => $wallet,
            'currentMembership' => $membership,
            'walletOptions' => self::walletsOf($memberships),
        ]);

        try {
            return $next($request);
        } finally {
            $this->contexts->forget();
        }
    }

    /** Vínculos ativos da pessoa em carteiras do Finanças. */
    public static function membershipsOf(int|string $userId): Collection
    {
        $integrationId = Finance::integration()->getKey();

        return Membership::active()->where('user_id', $userId)
            ->whereHas('organization', fn ($q) => $q->where('integration_id', $integrationId))
            ->with('organization.integration')
            ->oldest()
            ->get();
    }

    /** @return Collection<int, array{membership: Membership, wallet: ?Wallet}> carteiras, a pessoal primeiro */
    public static function walletsOf(Collection $memberships): Collection
    {
        $wallets = Wallet::withoutGlobalScopes()->whereIn('organization_id', $memberships->pluck('organization_id'))->get()->keyBy('organization_id');

        return $memberships
            ->map(fn (Membership $m) => ['membership' => $m, 'wallet' => $wallets->get($m->organization_id)])
            ->sortBy(fn ($o) => [$o['wallet']?->kind === Wallet::PERSONAL ? 0 : 1, $o['membership']->created_at])
            ->values();
    }

    /** Sem escolha na sessão: entra na carteira pessoal de que a pessoa é dona (a primeira criada). */
    private static function defaultOf(Collection $memberships): ?Membership
    {
        return self::walletsOf($memberships)
            ->first(fn ($o) => $o['wallet']?->kind === Wallet::PERSONAL && $o['membership']->role === 'owner')['membership'] ?? null;
    }
}
