<?php

namespace App\Commerce\Billing;

use App\Commerce\Commerce;
use App\Platform\Billing\Subscription;
use App\Platform\Organizations\Organization;

/**
 * Planos e limites do Commerce (config/commerce.php). Única regra de limite do produto: não repetir em tela.
 */
class Plans
{
    public static function all(): array
    {
        return config('commerce.plans');
    }

    public static function subscription(Organization $organization): ?Subscription
    {
        return Subscription::for($organization, Commerce::SLUG);
    }

    /**
     * Limite em vigor (null = sem limite). Fundador e teste usam os limites do teste; pagante, os do plano.
     */
    public static function limit(Organization $organization, string $key): ?int
    {
        $subscription = self::subscription($organization);
        $plan = $subscription?->isPaying() ? config("commerce.plans.{$subscription->plan}") : null;
        $limits = $plan['limits'] ?? config('commerce.trial_limits');

        return $limits[$key] ?? null;
    }

    public static function name(?string $plan): ?string
    {
        return $plan ? config("commerce.plans.{$plan}.name", $plan) : null;
    }

    public static function priceLabel(string $plan): string
    {
        return 'R$ '.number_format(config("commerce.plans.{$plan}.price_cents") / 100, 2, ',', '.').'/mês';
    }
}
