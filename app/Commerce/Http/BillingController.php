<?php

namespace App\Commerce\Http;

use App\Commerce\Billing\Plans;
use App\Platform\Audit\Audit;
use App\Platform\Billing\BillingGateway;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinatura do Commerce pelo Stripe (docs/09, F3): Checkout para assinar, portal para cartão, faturas e
 * cancelamento. A situação só muda quando o webhook do Stripe confirma.
 */
class BillingController
{
    public function show(): View
    {
        return view('commerce.billing', [
            'subscription' => Plans::subscription(ExecutionContext::current()->organization),
            'plans' => Plans::all(),
        ]);
    }

    public function checkout(Request $request, BillingGateway $gateway): Response
    {
        $data = $request->validate(['plan' => ['required', Rule::in(array_keys(Plans::all()))]]);
        $subscription = Plans::subscription(ExecutionContext::current()->organization);
        abort_unless($subscription, 404);
        $price = config("commerce.plans.{$data['plan']}.stripe_price");

        if ($subscription->isPaying()) {
            return redirect()->route('commerce.billing')->withErrors(['billing' => 'A loja já tem assinatura. Para trocar de plano ou de cartão, use "Gerenciar assinatura".']);
        }
        if (! $price) {
            return redirect()->route('commerce.billing')->withErrors(['billing' => 'Este plano ainda não está disponível para assinatura. Fale com a Foxy.']);
        }

        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'client_reference_id' => $subscription->id,
            'subscription_data' => ['metadata' => ['subscription_ref' => $subscription->id, 'product' => $subscription->product]],
            'success_url' => route('commerce.billing.thanks'),
            'cancel_url' => route('commerce.billing'),
            'locale' => 'pt-BR',
            'allow_promotion_codes' => true,
        ];
        $subscription->stripe_customer_id
            ? $params['customer'] = $subscription->stripe_customer_id
            : $params['customer_email'] = $request->user()->email;

        // Quem assina durante o teste só paga quando o teste acabar (o Stripe exige pelo menos 2 dias de antecedência).
        if ($subscription->trial_ends_at?->gt(now()->addDays(2))) {
            $params['subscription_data']['trial_end'] = $subscription->trial_ends_at->getTimestamp();
        }

        Audit::record('commerce.billing.checkout_started', subject: $subscription, metadata: ['plan' => $data['plan']]);

        return redirect()->away($gateway->checkoutUrl($params));
    }

    public function portal(BillingGateway $gateway): RedirectResponse
    {
        $subscription = Plans::subscription(ExecutionContext::current()->organization);
        abort_unless($subscription, 404);

        if (! $subscription->stripe_customer_id) {
            return redirect()->route('commerce.billing')->withErrors(['billing' => 'A loja ainda não tem assinatura no Stripe.']);
        }

        return redirect()->away($gateway->portalUrl($subscription->stripe_customer_id, route('commerce.billing')));
    }

    public function thanks(): RedirectResponse
    {
        return redirect()->route('commerce.billing')->with('status', 'Recebemos o seu pedido de assinatura. Assim que o Stripe confirmar o pagamento, a situação aparece aqui (costuma levar segundos).');
    }
}
