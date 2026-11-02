<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\ModelResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Planos por cliente, faixas de modelo e créditos proporcionais ao custo (docs/07, seção 2).
 */
class PlansAndCreditsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    private string $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['foxy.ai.default_plan' => null]);
        $this->setUpFakeAi();

        [, $this->key] = $this->integrationWithKey();
        $this->createProfile($this->key);
        $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo/tools', ['tools' => [ToolCatalogApiTest::validTool()]])->assertOk();
        $this->clinic = $this->withToken($this->key)->postJson('/api/v1/organizations', ['name' => 'Clínica'])->json('id');
    }

    private function setPlan(string $plan)
    {
        return $this->withToken($this->key)->putJson("/api/v1/organizations/{$this->clinic}/ai-subscription", ['plan' => $plan]);
    }

    private function open(array $payload = [])
    {
        return $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)
            ->postJson('/api/v1/conversations', $payload + ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1']);
    }

    private function ask(string $id)
    {
        return $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)
            ->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'oi']);
    }

    /** Resposta que custa exatamente US$ 1,00 no Haiku (= 1.000 créditos). */
    private function pushOneDollar(): void
    {
        $this->ai->push(new ModelResponse('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => 'ok']], 10_000_000, 0));
    }

    public function test_lists_the_plans(): void
    {
        $plans = $this->withToken($this->key)->getJson('/api/v1/ai/plans')->assertOk()->json('data');

        $this->assertSame([250, 1000, 1500, 2000, 5000, 10000, 25000, 50000], array_column($plans, 'credits_per_month'));
        $this->assertSame(['suporte', 'erros', 'dados', 'escrita'], $plans[0]['capabilities']);
        $this->assertSame(['rapida', 'equilibrada', 'avancada'], $plans[0]['tiers']);
    }

    public function test_client_without_plan_has_no_ai(): void
    {
        $this->open()->assertForbidden()->assertJsonPath('error.code', 'no_ai_plan');
    }

    public function test_system_sets_the_plan_and_it_is_audited(): void
    {
        $this->setPlan('ia-5000')
            ->assertOk()
            ->assertJsonPath('plan.code', 'ia-5000')
            ->assertJsonPath('credits_included', 5000)
            ->assertJsonPath('credits_available', 5000);

        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.subscription_set', 'organization_id' => $this->clinic]);
        $this->setPlan('nao-existe')->assertStatus(422);
    }

    public function test_plan_decides_the_model_tier(): void
    {
        $this->setPlan($this->defineBasicAiPlan());

        $this->open()->assertCreated()->assertJsonPath('tier', 'rapida')->assertJsonPath('model', 'claude-haiku-5-5');
        $this->open(['tier' => 'avancada'])->assertForbidden()->assertJsonPath('error.code', 'tier_not_in_plan');

        $this->setPlan('ia-25000');
        $this->open(['tier' => 'avancada'])->assertCreated()->assertJsonPath('model', 'claude-opus-5-5');
    }

    public function test_changing_the_model_of_a_tier_needs_no_plan_change(): void
    {
        config(['foxy.ai.tiers.rapida' => 'claude-sonnet-5-5']);
        $this->setPlan('ia-1000');

        $this->open()->assertJsonPath('tier', 'rapida')->assertJsonPath('model', 'claude-sonnet-5-5');
    }

    public function test_small_plans_do_not_offer_data_queries(): void
    {
        $this->setPlan($this->defineBasicAiPlan());
        $this->open(['allowed_tools' => ['financeiro_saldos_em_aberto']])->assertCreated()->assertJsonPath('allowed_tools', []);

        $this->setPlan('ia-5000');
        $this->open(['allowed_tools' => ['financeiro_saldos_em_aberto']])->assertJsonPath('allowed_tools', ['financeiro_saldos_em_aberto']);
    }

    public function test_credits_are_spent_in_proportion_to_cost_and_block_when_over(): void
    {
        $this->setPlan('ia-1000');
        $id = $this->open()->json('conversation_id');

        $this->pushOneDollar();
        $this->ask($id)->assertOk();

        $this->withToken($this->key)->getJson("/api/v1/organizations/{$this->clinic}/ai-subscription")
            ->assertJsonPath('credits_used', 1000)
            ->assertJsonPath('credits_available', 0)
            ->assertJsonPath('percent_used', 100);

        $this->ask($id)->assertStatus(429)->assertJsonPath('error.code', 'credit_limit');
    }

    public function test_extra_credits_in_packs_of_1000_unblock_the_month(): void
    {
        $this->setPlan('ia-1000');
        $id = $this->open()->json('conversation_id');
        $this->pushOneDollar();
        $this->ask($id)->assertOk();

        $this->withToken($this->key)->postJson("/api/v1/organizations/{$this->clinic}/ai-credits", ['credits' => 1500])->assertStatus(422);
        $this->withToken($this->key)->postJson("/api/v1/organizations/{$this->clinic}/ai-credits", ['credits' => 2000, 'reference' => 'pedido-77'])
            ->assertCreated()
            ->assertJsonPath('credits_extra', 2000)
            ->assertJsonPath('credits_available', 2000);

        $this->ask($id)->assertOk();
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.credits_added']);
    }

    public function test_extra_credits_are_only_for_the_month_they_were_bought(): void
    {
        $this->setPlan('ia-1000');
        $this->withToken($this->key)->postJson("/api/v1/organizations/{$this->clinic}/ai-credits", ['credits' => 3000]);

        $this->travel(1)->months();

        $this->withToken($this->key)->getJson("/api/v1/organizations/{$this->clinic}/ai-subscription")
            ->assertJsonPath('credits_extra', 0)
            ->assertJsonPath('credits_available', 1000);
    }

    public function test_suspended_ai_subscription_blocks_new_messages(): void
    {
        $this->setPlan('ia-5000');
        $id = $this->open()->json('conversation_id');

        $this->withToken($this->key)->putJson("/api/v1/organizations/{$this->clinic}/ai-subscription", ['plan' => 'ia-5000', 'status' => 'suspended'])->assertOk();

        $this->ask($id)->assertForbidden()->assertJsonPath('error.code', 'no_ai_plan');
    }

    public function test_usage_shows_credits(): void
    {
        $this->setPlan('ia-10000');

        $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)->getJson('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('credits.plan.code', 'ia-10000')
            ->assertJsonPath('credits.credits_available', 10000);
    }

    public function test_billing_never_reaches_a_client_of_another_system(): void
    {
        [, $otherKey] = $this->integrationWithKey('Outro sistema');

        $this->withToken($otherKey)->putJson("/api/v1/organizations/{$this->clinic}/ai-subscription", ['plan' => 'ia-50000'])->assertNotFound();
        $this->withToken($otherKey)->postJson("/api/v1/organizations/{$this->clinic}/ai-credits", ['credits' => 1000])->assertNotFound();
    }
}
