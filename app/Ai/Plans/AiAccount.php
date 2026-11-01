<?php

namespace App\Ai\Plans;

use App\Platform\Context\ExecutionContext;
use App\Platform\Entitlements\Entitlements;

/**
 * Plano e créditos do cliente do contexto atual. O consumo é medido em milicréditos no medidor
 * "ai.credits" do Core (1 crédito = 1.000 milicréditos = US$ 0,001 de custo de IA).
 */
class AiAccount
{
    public const CREDITS_METER = 'ai.credits';

    public function __construct(private Entitlements $entitlements) {}

    public function plan(): ?AiPlan
    {
        $subscription = AiSubscription::first();

        if ($subscription) {
            return $subscription->status === 'active' ? AiPlan::find($subscription->plan) : null;
        }

        return AiPlan::find(config('foxy.ai.default_plan'));
    }

    public function extraCredits(?string $period = null): int
    {
        return (int) AiCreditGrant::where('period', $period ?? now()->format('Y-m'))->sum('credits');
    }

    public function usedCredits(?string $period = null): float
    {
        return $this->entitlements->usage(self::CREDITS_METER, null, $period)['quantity'] / 1000;
    }

    public function availableCredits(): float
    {
        $plan = $this->plan();

        return $plan ? max(0, $plan->credits + $this->extraCredits() - $this->usedCredits()) : 0;
    }

    public function hasCredits(): bool
    {
        return $this->availableCredits() > 0;
    }

    public function spend(float $costUsd): void
    {
        $milliCredits = (int) ceil($costUsd / config('foxy.ai.credit_usd') * 1000);

        if ($milliCredits > 0) {
            $this->entitlements->recordUsage(self::CREDITS_METER, ExecutionContext::current()->onBehalfOf, $milliCredits, $costUsd);
        }
    }

    public function summary(?string $period = null): array
    {
        $plan = $this->plan();
        $included = $plan?->credits ?? 0;
        $extra = $this->extraCredits($period);
        $used = round($this->usedCredits($period), 3);
        $total = $included + $extra;

        return [
            'plan' => $plan?->toArray(),
            'credits_included' => $included,
            'credits_extra' => $extra,
            'credits_used' => $used,
            'credits_available' => round(max(0, $total - $used), 3),
            'percent_used' => $total > 0 ? min(100, (int) round($used / $total * 100)) : null,
        ];
    }
}
