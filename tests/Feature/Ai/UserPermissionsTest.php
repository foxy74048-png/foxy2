<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Terceira camada de permissão: o que cada usuário pode usar (docs/07, seção 3). Sempre plano ∩ usuário.
 */
class UserPermissionsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    private string $clinic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();

        [, $this->key] = $this->integrationWithKey();
        $this->createProfile($this->key);
        $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo/tools', ['tools' => [ToolCatalogApiTest::validTool()]])->assertOk();
        $this->clinic = $this->withToken($this->key)->postJson('/api/v1/organizations', ['name' => 'Clínica'])->json('id');
    }

    private function open(array $payload)
    {
        return $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)
            ->postJson('/api/v1/conversations', $payload + ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1', 'allowed_tools' => ['financeiro_saldos_em_aberto']]);
    }

    private function plan(string $plan): void
    {
        $this->withToken($this->key)->putJson("/api/v1/organizations/{$this->clinic}/ai-subscription", ['plan' => $plan])->assertOk();
    }

    public function test_without_user_restriction_the_plan_decides(): void
    {
        $this->plan('ia-5000');

        $this->open([])->assertCreated()
            ->assertJsonPath('capabilities', ['suporte', 'erros', 'dados', 'escrita'])
            ->assertJsonPath('allowed_tools', ['financeiro_saldos_em_aberto']);
    }

    public function test_user_without_data_gets_no_queries_and_the_ai_is_told(): void
    {
        $this->plan('ia-5000');

        $this->open(['allowed_capabilities' => ['suporte', 'erros']])->assertCreated()
            ->assertJsonPath('capabilities', ['suporte', 'erros'])
            ->assertJsonPath('allowed_tools', []);

        $id = $this->open(['allowed_capabilities' => ['suporte', 'erros']])->json('conversation_id');
        $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)
            ->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'quem me deve?'])->assertOk();

        $request = $this->ai->lastRequest();
        $this->assertSame(['foxy_encaminhar_suporte', 'foxy_registrar_sugestao'], array_column($request->tools, 'name'));
        $this->assertStringContainsString('NÃO tem acesso aos dados do sistema', $request->system);
    }

    public function test_user_can_never_get_more_than_the_plan(): void
    {
        $this->plan($this->defineBasicAiPlan());

        $this->open(['allowed_capabilities' => ['suporte', 'erros', 'dados', 'escrita']])->assertCreated()
            ->assertJsonPath('capabilities', ['suporte', 'erros'])
            ->assertJsonPath('allowed_tools', []);
    }

    public function test_user_without_errors_capability_does_not_send_screen_errors_to_the_ai(): void
    {
        $this->plan('ia-5000');
        $id = $this->open(['allowed_capabilities' => ['suporte']])->json('conversation_id');

        $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic)
            ->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'e isso?', 'error' => ['message' => 'Horário indisponível']])->assertOk();

        $this->assertStringNotContainsString('Horário indisponível', $this->ai->lastRequest()->messages[0]['content'][0]['text']);
    }

    public function test_support_is_required(): void
    {
        $this->plan('ia-5000');

        $this->open(['allowed_capabilities' => ['dados']])->assertForbidden()->assertJsonPath('error.code', 'no_capabilities');
        $this->open(['allowed_capabilities' => ['voar']])->assertStatus(422);
    }
}
