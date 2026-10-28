<?php

namespace Tests\Feature\Demo;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Ai\InteractsWithFoxyAi;
use Tests\TestCase;

class ConsoleTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpFakeAi();
    }

    private function loggedIn(): self
    {
        return $this->actingAs(User::factory()->create());
    }

    private function setupDemo(): void
    {
        $this->artisan('foxy:demo:setup')->assertSuccessful();
    }

    private function chat(array $overrides = [])
    {
        return $this->postJson('/console/chat', $overrides + [
            'question' => 'Quem mais me deve este mês?',
            'role' => 'dono',
            'model' => 'claude-opus-5-5',
        ]);
    }

    public function test_console_requires_login(): void
    {
        $this->get('/console')->assertRedirect('/console/login');
        $this->postJson('/console/chat', [])->assertUnauthorized();
    }

    public function test_login_with_console_user_created_by_command(): void
    {
        $this->artisan('foxy:console-user:create', ['email' => 'equipe@foxy.test'])->assertSuccessful();
        $this->post('/console/login', ['email' => 'equipe@foxy.test', 'password' => 'errada'])->assertSessionHasErrors('email');

        $user = User::where('email', 'equipe@foxy.test')->firstOrFail();
        $user->forceFill(['password' => 'senha-de-teste-123'])->save();

        $this->post('/console/login', ['email' => 'equipe@foxy.test', 'password' => 'senha-de-teste-123'])->assertRedirect('/console');
        $this->get('/console')->assertOk()->assertSee('Console de testes');
    }

    public function test_page_warns_when_demo_is_not_configured(): void
    {
        $this->loggedIn()->get('/console')->assertOk()->assertSee('foxy:demo:setup');
    }

    public function test_setup_publishes_profile_knowledge_and_tools_through_the_api(): void
    {
        $this->setupDemo();

        $this->assertDatabaseHas('host_profiles', ['slug' => 'clinica-demo', 'current_knowledge_version' => 1, 'current_tools_version' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tools_published', 'principal_type' => 'api_client']);

        // Rodar de novo troca a chave e não duplica versões.
        $this->setupDemo();
        $this->assertDatabaseHas('host_profiles', ['current_knowledge_version' => 1, 'current_tools_version' => 1]);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_chat_runs_the_tools_on_demo_data_and_shows_cost(): void
    {
        $this->setupDemo();
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'financeiro_saldos_em_aberto', 'input' => ['periodo' => now()->format('Y-m'), 'limite' => 3]]])
            ->pushText('Quem mais deve é a Ana Souza.');

        $response = $this->loggedIn()->chat()
            ->assertOk()
            ->assertJsonPath('reply', 'Quem mais deve é a Ana Souza.')
            ->assertJsonPath('trace.0.tool', 'financeiro_saldos_em_aberto')
            ->assertJsonPath('new_conversation', true);

        $this->assertGreaterThan(0, $response->json('usage.cost_usd'));
        $this->assertSame('tool_result', $this->ai->lastRequest()->messages[2]['content'][0]['type']);

        // Mesma configuração: continua a conversa.
        $this->chat(['question' => 'E o segundo?'])->assertOk()->assertJsonPath('new_conversation', false);
    }

    public function test_reception_role_does_not_get_financial_tools(): void
    {
        $this->setupDemo();

        $this->loggedIn()->chat(['role' => 'recepcao'])->assertOk();

        $tools = array_column($this->ai->lastRequest()->tools, 'name');
        $this->assertNotContains('financeiro_saldos_em_aberto', $tools);
        $this->assertContains('agenda_atendimentos', $tools);
    }

    public function test_changing_model_opens_new_conversation_with_that_model(): void
    {
        $this->setupDemo();
        $this->loggedIn()->chat()->assertOk();

        $this->chat(['model' => 'claude-haiku-5-5'])->assertOk()->assertJsonPath('new_conversation', true);
        $this->assertSame('claude-haiku-5-5', $this->ai->lastRequest()->model);
    }

    public function test_compare_asks_the_same_question_to_each_model(): void
    {
        $this->setupDemo();

        $response = $this->loggedIn()->postJson('/console/compare', [
            'question' => 'Como remarco um atendimento?',
            'role' => 'dono',
            'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'],
        ])->assertOk();

        $this->assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], array_column($response->json('results'), 'model'));
        $this->assertSame(['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], array_map(fn ($r) => $r->model, $this->ai->requests));
    }

    public function test_api_rejects_models_outside_the_allowed_list(): void
    {
        [, $key] = $this->organizationWithKey();
        $this->createProfile($key);

        $this->withToken($key)->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u', 'model' => 'gpt-qualquer'])
            ->assertStatus(422);
    }
}
