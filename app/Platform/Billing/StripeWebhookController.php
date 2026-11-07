<?php

namespace App\Platform\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook do Stripe (público, sem CSRF). Só confia no evento depois de conferir a assinatura; cada evento é
 * processado uma vez. A situação vem sempre do objeto de assinatura do Stripe, nunca de parâmetro da tela.
 */
class StripeWebhookController
{
    public function __invoke(Request $request, BillingGateway $gateway): Response
    {
        try {
            $event = $gateway->parseWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (InvalidWebhook) {
            return response('Assinatura inválida.', 400);
        }

        $object = $event['data']['object'] ?? [];

        if (DB::table('stripe_events')->where('id', $event['id'])->exists()) {
            return response('Já processado.', 200);
        }

        try {
            DB::transaction(function () use ($event, $object, $gateway) {
                DB::table('stripe_events')->insert(['id' => $event['id'], 'type' => $event['type'], 'processed_at' => now()]);

                match ($event['type']) {
                    'checkout.session.completed' => $this->checkoutCompleted($object, $gateway),
                    'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' => $this->subscriptionUpdated($object),
                    default => null,
                };
            });
        } catch (QueryException $e) {
            // Evento repetido: já foi processado.
            if (DB::table('stripe_events')->where('id', $event['id'])->exists()) {
                return response('Já processado.', 200);
            }

            throw $e;
        }

        return response('ok', 200);
    }

    private function checkoutCompleted(array $session, BillingGateway $gateway): void
    {
        // A conta do Stripe é da Foxy toda: pagamentos do Foxy Studio também chegam aqui e não são deste app.
        if (($session['mode'] ?? null) !== 'subscription' || empty($session['subscription']) || empty($session['client_reference_id'])) {
            return;
        }

        $subscription = Subscription::find($session['client_reference_id'] ?? null);
        if (! $subscription) {
            Log::warning('Stripe: checkout sem assinatura conhecida.', ['session' => $session['id'] ?? null]);

            return;
        }

        $subscription->fill([
            'stripe_customer_id' => $session['customer'] ?? $subscription->stripe_customer_id,
            'stripe_subscription_id' => $session['subscription'],
        ]);
        $this->apply($subscription, $gateway->subscription($session['subscription']));
    }

    private function subscriptionUpdated(array $stripe): void
    {
        $subscription = Subscription::where('stripe_subscription_id', $stripe['id'] ?? '')->first()
            ?? Subscription::find($stripe['metadata']['subscription_ref'] ?? null);

        if (! $subscription) {
            // Chega antes do checkout.session.completed: o checkout liga os dois depois.
            return;
        }

        $subscription->fill(['stripe_subscription_id' => $stripe['id'], 'stripe_customer_id' => $stripe['customer'] ?? $subscription->stripe_customer_id]);
        $this->apply($subscription, $stripe);
    }

    private function apply(Subscription $subscription, array $stripe): void
    {
        $item = $stripe['items']['data'][0] ?? [];
        $priceId = $item['price']['id'] ?? null;
        // Versões novas da API do Stripe trazem o fim do período no item; as antigas, na assinatura.
        $periodEnd = $item['current_period_end'] ?? $stripe['current_period_end'] ?? null;

        $subscription->fill([
            'status' => $stripe['status'],
            'plan' => $this->planFor($subscription->product, $priceId) ?? $subscription->plan,
            'current_period_end' => $periodEnd ? now()->setTimestamp($periodEnd) : null,
            'cancel_at_period_end' => (bool) ($stripe['cancel_at_period_end'] ?? false),
        ])->save();

        SubscriptionChanged::dispatch($subscription);
    }

    private function planFor(string $product, ?string $priceId): ?string
    {
        foreach (config("{$product}.plans", []) as $key => $plan) {
            if ($priceId && ($plan['stripe_price'] ?? null) === $priceId) {
                return $key;
            }
        }

        return null;
    }
}
