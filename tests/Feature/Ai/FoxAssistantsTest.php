<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As raposas: Chico, Lud, Lobinho e Ayla (docs/07, seção 4).
 */
class FoxAssistantsTest extends TestCase
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

    private function choose(array $payload)
    {
        return $this->withToken($this->key)->putJson('/api/v1/assistant-preferences', $payload);
    }

    private function openFor(string $userRef)
    {
        return $this->withToken($this->key)->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => $userRef]);
    }

    public function test_catalog_has_the_four_foxes_with_avatars(): void
    {
        $response = $this->withToken($this->key)->getJson('/api/v1/assistants')->assertOk();

        $this->assertSame(['Chico', 'Lud', 'Lobinho', 'Ayla'], array_column($response->json('data'), 'name'));
        $this->assertStringEndsWith('/core/assistants/chico.svg', $response->json('data.0.avatar_url'));
        $response->assertJsonPath('current.id', 'lud')->assertJsonPath('current.source', 'foxy');

        foreach (['chico', 'lud', 'lobinho', 'ayla'] as $id) {
            $this->assertFileExists(public_path("core/assistants/{$id}.svg"));
        }
    }

    public function test_avatar_url_always_uses_the_public_address_of_foxy_ai(): void
    {
        config(['app.url' => 'https://ai.foxy.test/']);

        $this->withToken($this->key)->getJson('/api/v1/assistants')
            ->assertJsonPath('data.0.avatar_url', 'https://ai.foxy.test/core/assistants/chico.svg');
    }

    public function test_without_choice_the_default_fox_answers(): void
    {
        $this->openFor('u-1')->assertCreated()
            ->assertJsonPath('assistant.id', 'lud')
            ->assertJsonPath('assistant.name', 'Lud');
    }

    public function test_account_default_and_personal_choice_with_custom_name(): void
    {
        $this->choose(['assistant' => 'ayla'])->assertOk()->assertJsonPath('current.source', 'conta');
        $this->choose(['user_ref' => 'u-2', 'assistant' => 'chico', 'custom_name' => 'Chiquinho da Recepção'])->assertOk();

        $this->openFor('u-1')->assertJsonPath('assistant.id', 'ayla')->assertJsonPath('assistant.name', 'Ayla');

        $this->openFor('u-2')
            ->assertJsonPath('assistant.id', 'chico')
            ->assertJsonPath('assistant.name', 'Chiquinho da Recepção');

        $this->withToken($this->key)->getJson('/api/v1/assistants?user_ref=u-2')->assertJsonPath('current.custom', true);
    }

    public function test_fox_name_and_style_go_into_the_rules_of_the_conversation(): void
    {
        $this->choose(['user_ref' => 'u-1', 'assistant' => 'lobinho', 'custom_name' => 'Lobão']);
        $id = $this->openFor('u-1')->json('conversation_id');

        $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'quem é você?'])->assertOk();

        $system = $this->ai->lastRequest()->system;
        $this->assertStringContainsString('Você é Lobão, o assistente da Foxy', $system);
        $this->assertStringContainsString('Animado e leve', $system);
    }

    public function test_changing_the_fox_does_not_change_an_open_conversation(): void
    {
        $id = $this->openFor('u-1')->json('conversation_id');
        $this->choose(['user_ref' => 'u-1', 'assistant' => 'ayla']);

        $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'oi'])->assertOk();

        $this->assertStringContainsString('Você é Lud', $this->ai->lastRequest()->system);
    }

    public function test_back_to_account_default_and_invalid_input(): void
    {
        $this->choose(['assistant' => 'chico']);
        $this->choose(['user_ref' => 'u-1', 'assistant' => 'ayla']);

        $this->withToken($this->key)->deleteJson('/api/v1/assistant-preferences?user_ref=u-1')
            ->assertOk()
            ->assertJsonPath('current.id', 'chico');

        $this->choose(['assistant' => 'gato'])->assertStatus(422);
        $this->choose(['assistant' => 'lud', 'custom_name' => '<script>'])->assertStatus(422);
        $this->choose(['assistant' => 'lud', 'custom_name' => str_repeat('a', 41)])->assertStatus(422);
    }

    public function test_choices_belong_to_each_client(): void
    {
        $this->choose(['assistant' => 'ayla']);
        [, $otherKey] = $this->organizationWithKey('Outra clínica');
        $this->createProfile($otherKey);

        $this->withToken($otherKey)->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1'])
            ->assertJsonPath('assistant.id', 'lud');
    }
}
