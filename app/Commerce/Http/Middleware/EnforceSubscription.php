<?php

namespace App\Commerce\Http\Middleware;

use App\Commerce\Billing\Plans;
use App\Platform\Billing\Subscription;
use App\Platform\Context\ExecutionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Depois de ResolveStore. Loja suspensa pela Foxy: nada abre. Teste vencido ou assinatura cancelada: só leitura
 * (dá para ver tudo e assinar, não dá para alterar). Os dados nunca são apagados.
 */
class EnforceSubscription
{
    /** Ações que funcionam mesmo em só leitura (para conseguir pagar e sair). */
    private const ALWAYS_ALLOWED = ['commerce.billing', 'commerce.billing.*', 'commerce.logout', 'commerce.stores.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $organization = ExecutionContext::current()->organization;

        if ($organization->status === 'suspended') {
            return response()->view('commerce.suspended', [], 403);
        }

        $subscription = Plans::subscription($organization);
        $access = $subscription?->access() ?? Subscription::READ_ONLY;
        view()->share(['currentSubscription' => $subscription, 'readOnly' => $access === Subscription::READ_ONLY]);

        if ($access === Subscription::READ_ONLY && ! $request->isMethodSafe() && ! $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return redirect()->route('commerce.billing')->withErrors(['billing' => 'O teste grátis acabou ou a assinatura foi cancelada: a loja está só para consulta. Assine para voltar a alterar.']);
        }

        return $next($request);
    }
}
