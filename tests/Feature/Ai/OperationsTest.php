<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\ModelResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->organizationWithKey('Clínica A');
        $this->createProfile($this->key);
    }

    private function talk(string $userRef, int $messages = 1): string
    {
        $id = $this->openConversation($this->key, $userRef);

        for ($i = 0; $i < $messages; $i++) {
            $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'oi'])->assertOk();
        }

        return $id;
    }

    public function test_expired_conversations_are_purged_daily_in_every_organization(): void
    {
        [, $otherKey] = $this->organizationWithKey('Clínica B');
        $this->createProfile($otherKey);

        $old = $this->talk('u-1');
        $this->openConversation($otherKey, 'u-9');
        $this->travel(31)->days();
        $recent = $this->talk('u-2');

        $this->artisan('foxy:ai:purge-conversations')->expectsOutputToContain('Conversas apagadas: 2')->assertSuccessful();

        $this->assertDatabaseMissing('conversations', ['id' => $old]);
        $this->assertDatabaseHas('conversations', ['id' => $recent]);
        $this->assertDatabaseMissing('conversation_messages', ['conversation_id' => $old]);
        // O registro de uso fica (sem texto), só perde o vínculo com a conversa.
        $this->assertDatabaseHas('usage_records', ['user_ref' => 'u-1', 'conversation_id' => null]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.conversations_purged', 'principal_type' => 'system']);
    }

    public function test_host_can_delete_all_conversations_of_a_user(): void
    {
        $this->talk('u-1');
        $this->talk('u-1');
        $keep = $this->talk('u-2');

        $this->withToken($this->key)->deleteJson('/api/v1/conversations?user_ref=u-1')
            ->assertOk()
            ->assertJson(['deleted' => 2]);

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', ['id' => $keep]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.conversations_deleted']);
    }

    public function test_deleting_never_touches_another_organization(): void
    {
        [, $otherKey] = $this->organizationWithKey('Clínica B');
        $this->createProfile($otherKey);
        $other = $this->openConversation($otherKey, 'u-1');

        $this->withToken($this->key)->deleteJson('/api/v1/conversations?user_ref=u-1')->assertJson(['deleted' => 0]);

        $this->assertDatabaseHas('conversations', ['id' => $other]);
    }

    public function test_monthly_usage_endpoint(): void
    {
        $this->ai->push(new ModelResponse('claude-opus-5-5', 'end_turn', [['type' => 'text', 'text' => 'a']], 1_000_000, 0))
            ->push(new ModelResponse('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => 'b']], 1_000_000, 0));
        $this->talk('u-1', 2);

        $this->withToken($this->key)->getJson('/api/v1/usage')
            ->assertOk()
            ->assertJsonPath('month', now()->format('Y-m'))
            ->assertJsonPath('messages', 2)
            ->assertJsonPath('cost_usd', 4.1)
            ->assertJsonPath('by_model.0.model', 'claude-haiku-5-5')
            ->assertJsonPath('by_model.1.calls', 1)
            ->assertJsonPath('credits_by_tier.avancada', 4000)
            ->assertJsonPath('credits_by_tier.rapida', 100);

        // A pessoa vê o próprio uso (tela de configurações da janela da raposa).
        $this->withToken($this->key)->getJson('/api/v1/usage?user_ref=u-1')
            ->assertJsonPath('user.messages', 2)
            ->assertJsonPath('user.credits', 4100);
        $this->withToken($this->key)->getJson('/api/v1/usage?user_ref=u-2')->assertJsonPath('user.messages', 0);

        $this->withToken($this->key)->getJson('/api/v1/usage?month=2020-01')->assertJsonPath('messages', 0)->assertJsonPath('by_model', []);
        $this->withToken($this->key)->getJson('/api/v1/usage?month=janeiro')->assertStatus(422);
    }

    public function test_usage_report_command(): void
    {
        $this->talk('u-1');

        $this->artisan('foxy:ai:usage')
            ->expectsOutputToContain('Clínica A')
            ->assertSuccessful();

        $this->artisan('foxy:ai:usage', ['--month' => 'x'])->assertFailed();
    }
}
