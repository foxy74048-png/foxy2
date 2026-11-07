<?php

namespace App\Platform\Billing;

use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeGateway implements BillingGateway
{
    private ?StripeClient $client = null;

    public function checkoutUrl(array $params): string
    {
        return $this->client()->checkout->sessions->create($params)->url;
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        return $this->client()->billingPortal->sessions->create(['customer' => $customerId, 'return_url' => $returnUrl])->url;
    }

    public function parseWebhook(string $payload, ?string $signature): array
    {
        try {
            return Webhook::constructEvent($payload, (string) $signature, (string) config('services.stripe.webhook_secret'))->toArray();
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new InvalidWebhook($e->getMessage(), previous: $e);
        }
    }

    public function subscription(string $id): array
    {
        return $this->client()->subscriptions->retrieve($id)->toArray();
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }
}
