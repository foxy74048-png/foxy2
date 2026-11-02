<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedidos de algo que o sistema ainda não tem (a IA anota para a equipe) e histórico recente por plano.
 */
class FeatureRequestsAndHistoryTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->organizationWithKey();
        $this->createProfile($this->key);
    }

    private function send(string $id, string $text)
    {
        return $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", ['text' => $text]);
    }

    public function test_missing_feature_is_returned_to_the_host_and_audited(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'foxy_registrar_sugestao', 'input' => ['pedido' => 'Emitir nota fiscal direto pelo atendimento.']]])
            ->pushText('Isso ainda não existe, mas anotei a sua ideia.');

        $this->send($id, 'Como emito nota fiscal?')
            ->assertOk()
            ->assertJsonPath('reply', 'Isso ainda não existe, mas anotei a sua ideia.')
            ->assertJsonPath('feature_request.summary', 'Emitir nota fiscal direto pelo atendimento.')
            ->assertJsonPath('escalate', null);

        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.feature_requested']);
        $this->assertStringContainsString('foxy_registrar_sugestao', $this->ai->lastRequest()->system);
    }

    public function test_question_the_manual_does_not_answer_is_returned_to_the_host(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'foxy_registrar_duvida', 'input' => ['duvida' => 'Como mudo a cor do meu site?']]])
            ->pushText('Não tenho essa informação agora. Quer que eu encaminhe ao suporte?');

        $this->send($id, 'Como mudo a cor do meu site?')
            ->assertOk()
            ->assertJsonPath('knowledge_gap.summary', 'Como mudo a cor do meu site?')
            ->assertJsonPath('feature_request', null);

        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.knowledge_gap']);
    }

    public function test_system_sees_the_month_usage_of_each_client(): void
    {
        [$integration, $systemKey] = $this->integrationWithKey('Foxy');
        $org = $this->withToken($systemKey)->postJson('/api/v1/organizations', ['name' => 'Studio A', 'external_ref' => 'ws-1'])->json('id');
        $this->withToken($systemKey)->putJson("/api/v1/organizations/{$org}/ai-subscription", ['plan' => 'ia-1000'])->assertOk();

        $usage = $this->withToken($systemKey)->getJson('/api/v1/ai/usage')->assertOk();

        $this->assertSame([['messages' => 0, 'credits' => 0, 'credits_by_tier' => [], 'cost_usd' => 0, 'organization_id' => $org, 'name' => 'Studio A', 'external_ref' => 'ws-1', 'plan' => 'ia-1000']], $usage->json('data'));
        // Só os clientes deste sistema.
        $this->withToken($this->key)->getJson('/api/v1/ai/usage')->assertOk()->assertJsonMissing(['name' => 'Studio A']);
    }

    public function test_empty_feature_request_goes_back_to_the_model_as_error(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'foxy_registrar_sugestao', 'input' => ['pedido' => '  ']]])
            ->pushText('Pode me contar o que queria fazer?');

        $this->send($id, 'queria uma coisa')->assertOk()->assertJsonPath('feature_request', null);
        $this->assertTrue($this->ai->lastRequest()->messages[2]['content'][0]['is_error']);
    }

    public function test_plan_decides_how_long_history_is_kept_and_how_long_a_conversation_can_be(): void
    {
        $this->withToken($this->key)->getJson('/api/v1/ai/plans')->assertOk()
            ->assertJsonPath('data.0.code', 'ia-250')
            ->assertJsonPath('data.0.retention_days', 7)
            ->assertJsonPath('data.0.max_user_messages', 10)
            ->assertJsonPath('data.7.retention_days', 30);

        config(['foxy.ai.default_plan' => 'ia-250']);
        $this->travelTo(now()->startOfDay());
        $response = $this->withToken($this->key)->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1'])->assertCreated();

        $this->assertSame(now()->addDays(7)->toIso8601String(), $response->json('expires_at'));
    }

    public function test_history_shows_only_the_texts_exchanged(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'agenda_atendimentos', 'input' => []]]);
        $this->ai->pushText('Primeira resposta.');
        $this->send($id, 'Oi, tudo bem?');

        $history = $this->withToken($this->key)->getJson("/api/v1/conversations/{$id}/messages")->assertOk();

        $this->assertSame([['role' => 'user', 'text' => 'Oi, tudo bem?']], array_slice($history->json('messages'), 0, 1));
        $this->assertStringNotContainsString('<contexto>', json_encode($history->json('messages')));
    }

    public function test_history_of_another_client_is_not_found(): void
    {
        $id = $this->openConversation($this->key);
        [, $otherKey] = $this->organizationWithKey('Outra clínica');

        $this->withToken($otherKey)->getJson("/api/v1/conversations/{$id}/messages")->assertNotFound();
    }
}
