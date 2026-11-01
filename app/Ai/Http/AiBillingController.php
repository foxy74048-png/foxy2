<?php

namespace App\Ai\Http;

use App\Ai\Plans\AiAccount;
use App\Ai\Plans\AiCreditGrant;
use App\Ai\Plans\AiPlan;
use App\Ai\Plans\AiSubscription;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O sistema define o plano de IA de cada cliente e soma créditos extras (docs/07, seção 2.4).
 * A Foxy AI não cobra ninguém: quem cobra o cliente é o sistema.
 */
class AiBillingController
{
    public function __construct(private ContextStore $contexts, private AiAccount $account) {}

    public function plans(): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (AiPlan $plan) => $plan->toArray(), AiPlan::all()),
            'tiers' => array_keys(config('foxy.ai.tiers')),
            'extra_credits_step' => config('foxy.ai.extra_credits_step'),
        ]);
    }

    public function putSubscription(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(config('foxy.ai.plans')))],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ]);

        return $this->asClient($id, function () use ($data) {
            $subscription = AiSubscription::firstOrNew();
            $before = $subscription->exists ? $subscription->plan : null;
            $subscription->fill(['plan' => $data['plan'], 'status' => $data['status'] ?? 'active'])->save();

            Audit::record('ai.subscription_set', 'ok', null, ['plan' => $data['plan'], 'previous_plan' => $before, 'status' => $subscription->status]);

            return response()->json($this->account->summary());
        });
    }

    public function addCredits(Request $request, string $id): JsonResponse
    {
        $step = config('foxy.ai.extra_credits_step');
        $data = $request->validate([
            'credits' => ['required', 'integer', "min:{$step}", 'max:1000000', "multiple_of:{$step}"],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->asClient($id, function () use ($data) {
            AiCreditGrant::create(['period' => now()->format('Y-m'), 'credits' => $data['credits'], 'reference' => $data['reference'] ?? null]);
            Audit::record('ai.credits_added', 'ok', null, ['credits' => $data['credits'], 'reference' => $data['reference'] ?? null]);

            return response()->json($this->account->summary(), 201);
        });
    }

    public function showForClient(string $id): JsonResponse
    {
        return $this->asClient($id, fn () => response()->json($this->account->summary()));
    }

    private function asClient(string $id, callable $callback): JsonResponse
    {
        $context = ExecutionContext::current();
        $organization = Organization::where('integration_id', $context->integration->getKey())->findOrFail($id);

        return $this->contexts->run($context->withOrganization($organization), $callback);
    }
}
