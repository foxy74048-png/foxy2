<?php

namespace Tests\Feature\Ai;

use App\Ai\Conversations\Conversation;
use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\ProviderUnavailable;
use App\Platform\Organizations\OrganizationScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationApiTest extends TestCase
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

    private function send(string $conversationId, string $text = 'Como remarco um atendimento?', ?string $page = null)
    {
        return $this->withToken($this->key)->postJson("/api/v1/conversations/{$conversationId}/messages", array_filter([
            'text' => $text,
            'page' => $page,
        ]));
    }

    public function test_opening_requires_published_knowledge(): void
    {
        $this->withToken($this->key)->putJson('/api/v1/host-profiles/vazio', ['name' => 'Vazio']);

        $this->withToken($this->key)->postJson('/api/v1/conversations', ['host_profile' => 'vazio', 'user_ref' => 'u-1'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'knowledge_missing');
    }

    public function test_answers_a_usage_question_with_knowledge_date_and_screen_context(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        $id = $this->openConversation($this->key);
        $this->ai->pushText('Abra a Agenda, clique no atendimento e depois em Remarcar.');

        $this->send($id, page: 'Agenda › Semana')
            ->assertOk()
            ->assertJsonPath('status', 'answered')
            ->assertJsonPath('reply', 'Abra a Agenda, clique no atendimento e depois em Remarcar.')
            ->assertJsonPath('escalate', null)
            ->assertJsonPath('usage.model', 'claude-opus-5-5')
            ->assertJsonPath('usage.cache_read_tokens', 9000)
            ->assertJsonStructure(['request_id', 'usage' => ['cost_usd']]);

        $request = $this->ai->lastRequest();
        $this->assertSame('claude-opus-5-5', $request->model);
        $this->assertSame('low', $request->effort);
        $this->assertStringContainsString('Agenda › clique no atendimento › Remarcar.', $request->system);
        $this->assertStringContainsString('Agenda Demo', $request->system);
        $this->assertStringNotContainsString('2026-10-28', $request->system, 'A data não pode ficar nas regras fixas (quebraria o cache).');

        $context = $request->messages[0]['content'][0]['text'];
        $this->assertStringContainsString('2026-10-28', $context);
        $this->assertStringContainsString('Tela atual: Agenda › Semana', $context);
        $this->assertSame('Como remarco um atendimento?', $request->messages[0]['content'][1]['text']);
    }

    public function test_history_is_append_only_and_rules_stay_identical(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->pushText('Primeira resposta.')->pushText('Segunda resposta.');

        $this->send($id, 'Pergunta 1')->assertOk();
        $first = $this->ai->lastRequest();
        $this->send($id, 'Pergunta 2')->assertOk();
        $second = $this->ai->lastRequest();

        $this->assertSame($first->system, $second->system);
        $this->assertSame($first->tools, $second->tools);
        // O início da segunda chamada é exatamente a primeira + a resposta do modelo, com o raciocínio (thinking) intacto.
        $this->assertSame($first->messages[0], $second->messages[0]);
        $this->assertSame('assistant', $second->messages[1]['role']);
        $this->assertSame(['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-0'], $second->messages[1]['content'][0]);
        $this->assertCount(3, $second->messages);
    }

    public function test_new_knowledge_does_not_change_an_open_conversation(): void
    {
        $id = $this->openConversation($this->key);
        $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo/knowledge', ['content' => 'Manual novo'])->assertOk();

        $this->send($id)->assertOk();
        $this->assertStringNotContainsString('Manual novo', $this->ai->lastRequest()->system);

        $newId = $this->openConversation($this->key);
        $this->send($newId)->assertOk();
        $this->assertStringContainsString('Manual novo', $this->ai->lastRequest()->system);
    }

    public function test_conversation_of_another_organization_is_not_found(): void
    {
        $id = $this->openConversation($this->key);
        [, $otherKey] = $this->organizationWithKey('Outra');

        $this->withToken($otherKey)->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'oi'])
            ->assertNotFound();
        $this->assertCount(0, $this->ai->requests);
    }

    public function test_provider_failure_returns_503_and_keeps_conversation_usable(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->push(new ProviderUnavailable('caiu'))->pushText('Agora foi.');

        $this->send($id)->assertStatus(503)->assertJsonPath('error.code', 'ai_unavailable');
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('usage_records', 0);

        $this->send($id)->assertOk()->assertJsonPath('reply', 'Agora foi.');
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_records_usage_and_cost_without_storing_text(): void
    {
        $id = $this->openConversation($this->key, 'u-42');
        $this->ai->push(new ModelResponse('claude-opus-5-5', 'end_turn', [['type' => 'text', 'text' => 'ok']], 1_000_000, 100_000, 1_000_000, 0));

        $this->send($id)->assertOk()->assertJsonPath('usage.cost_usd', 6.2);

        $this->assertDatabaseHas('usage_records', ['user_ref' => 'u-42', 'model' => 'claude-opus-5-5', 'input_tokens' => 1_000_000]);
        $this->assertDatabaseHas('usage_counters', ['meter' => 'ai.messages', 'subject_ref' => 'u-42', 'quantity' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.message_answered', 'principal_type' => 'ai_agent', 'on_behalf_of' => 'u-42']);
        $auditText = \DB::table('audit_entries')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('remarco', $auditText);    }

    public function test_monthly_usage_limit_per_user(): void
    {
        [, $key] = $this->organizationWithKey('Limitada', ['*'], ['limits' => ['ai.messages.monthly_per_subject' => 1]]);
        $this->key = $key;
        $this->createProfile($key);
        $id = $this->openConversation($key, 'u-1');

        $this->send($id)->assertOk();
        $this->send($id)->assertStatus(429)->assertJsonPath('error.code', 'usage_limit');

        $other = $this->openConversation($key, 'u-2');
        $this->send($other)->assertOk();
    }

    public function test_per_user_rate_limit(): void
    {
        config(['foxy.ai.user_messages_per_minute' => 2]);
        $id = $this->openConversation($this->key);

        $this->send($id)->assertOk();
        $this->send($id)->assertOk();
        $this->send($id)->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_conversation_has_a_message_limit(): void
    {
        config(['foxy.ai.max_user_messages_per_conversation' => 2]);
        $id = $this->openConversation($this->key);

        $this->send($id)->assertOk();
        $this->send($id)->assertOk();
        $this->send($id)->assertStatus(409)->assertJsonPath('error.code', 'conversation_full');
    }

    public function test_expired_conversation(): void
    {
        $id = $this->openConversation($this->key);
        $this->travel(31)->days();

        $this->send($id)->assertStatus(410)->assertJsonPath('error.code', 'conversation_expired');
    }

    public function test_busy_conversation_is_rejected_and_stale_processing_is_recovered(): void
    {
        $id = $this->openConversation($this->key);
        Conversation::withoutGlobalScope(OrganizationScope::class)->whereKey($id)
            ->update(['status' => Conversation::PROCESSING, 'processing_started_at' => now()]);

        $this->send($id)->assertStatus(409)->assertJsonPath('error.code', 'conversation_busy');

        $this->travel(4)->minutes();
        $this->send($id)->assertOk();
    }

    public function test_refusal_without_text_gets_a_safe_reply_kept_in_history(): void
    {
        $id = $this->openConversation($this->key);
        $this->ai->push(new ModelResponse('claude-opus-5-5', 'refusal', [], 100, 0));

        $this->send($id)->assertOk()->assertJsonPath('reply', 'Não consigo ajudar com esse pedido. Se precisar, posso encaminhar você para o suporte.');

        $this->send($id, 'Outra pergunta')->assertOk();
        $history = $this->ai->lastRequest()->messages;
        $this->assertSame('text', $history[1]['content'][0]['type']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.message_answered', 'result' => 'refused']);
    }

    public function test_validation_error_format(): void
    {
        $id = $this->openConversation($this->key);

        $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['text']]]);
    }
}
