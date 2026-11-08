<?php

namespace App\Commerce\Http\Middleware;

use App\Commerce\Billing\Plans;
use App\Commerce\Stores\Store;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Loja própria (pública, sem login): acha a loja pelo slug e monta o contexto dela. Só loja publicada; loja
 * suspensa ou com assinatura vencida mostra "indisponível". Daqui para frente o escopo filtra pela loja achada.
 */
class ResolvePublicStore
{
    public function __construct(private ContextStore $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = Store::withoutGlobalScopes()->with('organization.integration')->where('slug', (string) $request->route('slug'))->first();
        abort_unless($store?->isPublished(), 404);

        $this->contexts->set(ExecutionContext::forSystem($store->organization, 'storefront'));
        $subscription = Plans::subscription($store->organization);
        if ($store->organization->status === 'suspended' || ($subscription?->access() ?? Subscription::READ_ONLY) === Subscription::READ_ONLY) {
            $this->contexts->forget();

            return response()->view('storefront.unavailable', ['store' => $store], 503);
        }

        $request->attributes->set('storefront.store', $store);
        view()->share('store', $store);

        try {
            return $next($request);
        } finally {
            $this->contexts->forget();
        }
    }
}
