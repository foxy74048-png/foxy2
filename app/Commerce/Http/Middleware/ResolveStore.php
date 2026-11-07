<?php

namespace App\Commerce\Http\Middleware;

use App\Commerce\Commerce;
use App\Commerce\Stores\Store;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * App do lojista: acha a loja em que a pessoa está trabalhando (vínculo ativo; a escolhida na sessão ou a
 * única que tem) e monta o ExecutionContext dela. Daqui para frente, tudo o que tem BelongsToOrganization
 * já filtra por esta loja.
 */
class ResolveStore
{
    public const SESSION_KEY = 'commerce.organization_id';

    public function __construct(private ContextStore $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $memberships = self::membershipsOf($user->getKey());

        $membership = $memberships->firstWhere('organization_id', $request->session()->get(self::SESSION_KEY))
            ?? ($memberships->count() === 1 ? $memberships->first() : null);

        if (! $membership) {
            return redirect()->route($memberships->isEmpty() ? 'commerce.stores.new' : 'commerce.stores.choose');
        }

        $request->session()->put(self::SESSION_KEY, $membership->organization_id);
        $this->contexts->set(ExecutionContext::forUser($membership->organization, $user->getKey()));

        $store = Store::firstOrFail();
        $request->attributes->set('commerce.membership', $membership);
        $request->attributes->set('commerce.store', $store);
        view()->share(['currentStore' => $store, 'currentMembership' => $membership, 'storeCount' => $memberships->count()]);

        try {
            return $next($request);
        } finally {
            $this->contexts->forget();
        }
    }

    /**
     * Vínculos ativos da pessoa em lojas do Commerce.
     */
    public static function membershipsOf(int|string $userId)
    {
        $integrationId = Commerce::integration()->getKey();

        return Membership::active()->where('user_id', $userId)
            ->whereHas('organization', fn ($q) => $q->where('integration_id', $integrationId))
            ->with('organization.integration')
            ->get();
    }
}
