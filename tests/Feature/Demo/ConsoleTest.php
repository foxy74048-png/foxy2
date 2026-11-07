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
        return $this->actingAs(User::factory()->staff()->create());
    }

    private function setupDemo(): void
    {
        $this->artisan('foxy:demo:setup')->assertSuccessful();
    }

    private function message(array $overrides = [])
    {
        return $this->postJson('/console/assistant/messages', $overrides + [
            'text' => 'Quem mais me deve este mês?',
            'role' => 'dono',
            'model' => 'claude-haiku-5-5',
            'page' => 'Financeiro › Recebimentos',
        ]);
    }

    public function test_console_requires_login(): void
    {
        $this->get('/console')->assertRedirect('/console/login');
        $this->postJson('/console/assistant/messages', [])->assertUnauthorized();
    }

    public function test_login_with_console_user_created_by_command(): void
    {
        $this->get('/console/login')->assertOk()->assertSee('/core/foxy-core.css', false);
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

    public function test_page_uses_core_layout_widget_and_shows_the_four_foxes(): void
    {
        $this->setupDemo();

        $this->loggedIn()->get('/console')->assertOk()
            ->assertSee('/core/foxy-core.css', false)
            ->assertSee('/core/foxy-assistant.js', false)
            ->assertSee('FoxyAssistant.mount', false)
            ->assertSee('Chico')->assertSee('Lud')->assertSee('Lobinho')->assertSee('Ayla');
    }

    public function test_setup_publishes_profile_knowledge_and_tools_through_the_api(): void
    {
        $this->setupDemo();

        $this->assertDatabaseHas('host_profiles', ['slug' => 'clinica-demo', 'current_knowledge_version' => 1, 'current_tools_version' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tools_published', 'principal_type' => 'api_client']);
        $this->assertDatabaseHas('ai_subscriptions', ['plan' => 'ia-50000']);

        // Rodar de novo troca a chave e não duplica versões nem clientes.
        $this->setupDemo();
        $this->assertDatabaseHas('host_profiles', ['current_knowledge_version' => 1, 'current_tools_version' => 1]);
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_widget_message_runs_the_tools_on_demo_data_and_returns_widget_format(): void
    {
        $this->setupDemo();
        $this->ai->pushToolUse([['id' => 'toolu_1', 'name' => 'financeiro_saldos_em_aberto', 'input' => ['periodo' => now()->format('Y-m'), 'limite' => 3]]])
            ->pushText('Quem mais deve é a Ana Souza.');

        $response = $this->loggedIn()->message()
            ->assertOk()
            ->assertJsonPath('reply', 'Quem mais deve é a Ana Souza.')
            ->assertJsonPath('tools_used', ['Financeiro'])
            ->assertJsonPath('trace.0.tool', 'financeiro_saldos_em_aberto')
            ->assertJsonPath('assistant.name', 'Lud')
            ->assertJsonPath('new_conversation', true)
            ->assertJsonStructure(['drafts', 'escalate', 'usage' => ['cost_usd']]);

        $this->assertGreaterThan(0, $response->json('usage.cost_usd'));
        $this->assertStringContainsString('Tela atual: Financeiro › Recebimentos', $this->ai->requests[0]->messages[0]['content'][0]['text']);

        $this->message(['text' => 'E o segundo?'])->assertOk()->assertJsonPath('new_conversation', false);
    }

    public function test_screen_error_goes_to_the_ai(): void
    {
        $this->setupDemo();

        $this->loggedIn()->message(['text' => 'o que é isso?', 'error' => ['message' => 'Horário indisponível', 'code' => 'AG-409']])->assertOk();

        $this->assertStringContainsString('Horário indisponível (código AG-409)', $this->ai->lastRequest()->messages[0]['content'][0]['text']);
    }

    public function test_choosing_a_fox_and_a_name_starts_a_new_conversation_with_it(): void
    {
        $this->setupDemo();
        $this->loggedIn()->message()->assertOk();

        $this->postJson('/console/assistant/fox', ['assistant' => 'chico', 'custom_name' => 'Chicão', 'visual' => 'linhas'])
            ->assertOk()
            ->assertJsonPath('assistant.id', 'chico')
            ->assertJsonPath('assistant.name', 'Chicão')
            ->assertJsonPath('assistant.visual', 'linhas');

        $this->message()->assertJsonPath('new_conversation', true)->assertJsonPath('assistant.name', 'Chicão')->assertJsonPath('assistant.visual', 'linhas');
        $this->assertStringContainsString('Você é Chicão', $this->ai->lastRequest()->system);

        $this->get('/console')->assertSee('value="Chicão"', false)
            ->assertSee('Estilo do desenho')
            ->assertSee('/core/assistants/linhas/chico.svg', false);
        $this->postJson('/console/assistant/fox', ['assistant' => 'lud', 'custom_name' => '<b>'])->assertStatus(422);
    }

    public function test_reset_starts_a_new_conversation(): void
    {
        $this->setupDemo();
        $this->loggedIn()->message()->assertOk();

        $this->postJson('/console/assistant/reset')->assertOk()->assertJsonPath('assistant.id', 'lud');
        $this->message()->assertJsonPath('new_conversation', true);
    }

    public function test_reception_role_does_not_get_financial_tools(): void
    {
        $this->setupDemo();

        $this->loggedIn()->message(['role' => 'recepcao'])->assertOk();

        $tools = array_column($this->ai->lastRequest()->tools, 'name');
        $this->assertNotContains('financeiro_saldos_em_aberto', $tools);
        $this->assertContains('agenda_atendimentos', $tools);
    }

    public function test_changing_model_opens_new_conversation_with_that_model(): void
    {
        $this->setupDemo();
        $this->loggedIn()->message()->assertOk();
        $this->assertSame('claude-haiku-5-5', $this->ai->lastRequest()->model);

        $this->message(['model' => 'claude-opus-5-5'])->assertOk()->assertJsonPath('new_conversation', true);
        $this->assertSame('claude-opus-5-5', $this->ai->lastRequest()->model);
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

    public function test_core_assets_exist(): void
    {
        foreach (['core/foxy-core.css', 'core/foxy-assistant.css', 'core/foxy-assistant.js', 'core/brand/logo.png'] as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        $css = file_get_contents(public_path('core/foxy-core.css'));
        $this->assertStringContainsString('--primary: #5b3fd1', $css);
        foreach (['#D9A6F0', '#fcf9f5', '#b77f89'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $css, 'Nada de lilás/rosa/creme no Core.');
        }
    }

    public function test_api_rejects_models_outside_the_allowed_list(): void
    {
        [, $key] = $this->organizationWithKey();
        $this->createProfile($key);

        $this->withToken($key)->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u', 'model' => 'gpt-qualquer'])
            ->assertStatus(422);
    }
}
