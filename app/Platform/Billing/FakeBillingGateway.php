<?php

namespace App\Platform\Billing;

/**
 * Stripe de mentira para os testes: guarda o que foi pedido e aceita webhooks com a assinatura "valida".
 */
class FakeBillingGateway implements BillingGateway
{
    public array $checkouts = [];

    public array $portals = [];

    /** @var array<string, array> */
    public array $subscriptions = [];

    public function checkoutUrl(array $params): string
    {
        $this->checkouts[] = $params;

        return 'https://checkout.stripe.test/c/'.count($this->checkouts);
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        $this->portals[] = ['customer' => $customerId, 'return_url' => $returnUrl];

        return 'https://billing.stripe.test/p/'.$customerId;
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        if ($signature !== 'valida') {
            throw new InvalidWebhook('Assinatura inválida.');
        }

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    public function subscription(string $id): array
    {
        return $this->subscriptions[$id] ?? throw new InvalidWebhook("Assinatura {$id} não existe no Stripe falso.");
    }
}
