<?php

namespace App\Platform\Billing;

/**
 * Único ponto que fala com o provedor de cobrança da Foxy (Stripe). Nos testes, FakeBillingGateway.
 */
interface BillingGateway
{
    /** Cria a página de pagamento (Checkout) e devolve o endereço para onde mandar a pessoa. */
    public function checkoutUrl(array $params): string;

    /** Portal do provedor para trocar cartão, ver faturas e cancelar. */
    public function portalUrl(string $customerId, string $returnUrl): string;

    /**
     * Confere a assinatura do webhook e devolve o evento.
     *
     * @throws InvalidWebhook
     */
    public function parseWebhook(string $payload, ?string $signature): array;

    /** Assinatura como o provedor a vê agora. */
    public function subscription(string $id): array;
}
