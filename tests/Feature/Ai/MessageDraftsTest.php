<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sugestão de mensagens (capacidade "escrita", docs/07 seção 6): a IA entrega o texto num cartão; nunca envia.
 */
class MessageDraftsTest extends TestCase
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
        $this->plan('ia-5000');
    }

    private function plan(string $plan): void
    {
        $this->withToken($this->key)->putJson("/api/v1/organizations/{$this->clinic}/ai-subscription", ['plan' => $plan])->assertOk();
    }

    private function api()
    {
        return $this->withToken($this->key)->withHeader('X-Foxy-Organization', $this->clinic);
    }

    private function open(array $payload = []): string
    {
        return $this->api()->postJson('/api/v1/conversations', $payload + ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1', 'allowed_tools' => ['financeiro_saldos_em_aberto']])
            ->assertCreated()->json('conversation_id');
    }

    private function draftCall(string $id = 'toolu_d1', array $input = ['canal' => 'whatsapp', 'titulo' => 'Lembrete de amanhã', 'texto' => 'Olá, Ana! Passando para lembrar do seu atendimento amanhã às 10h. Até lá!']): array
    {
        return ['id' => $id, 'name' => 'foxy_sugerir_mensagem', 'input' => $input];
    }

    public function test_suggests_a_message_in_a_card_without_sending(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->draftCall()])->pushText('Pronto! A mensagem está logo abaixo para você revisar.');

        $this->api()->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'Escreve um lembrete para a Ana de amanhã'])
            ->assertOk()
            ->assertJsonPath('reply', 'Pronto! A mensagem está logo abaixo para você revisar.')
            ->assertJsonPath('drafts.0.channel', 'whatsapp')
            ->assertJsonPath('drafts.0.title', 'Lembrete de amanhã')
            ->assertJsonPath('drafts.0.text', 'Olá, Ana! Passando para lembrar do seu atendimento amanhã às 10h. Até lá!');

        $result = $this->ai->lastRequest()->messages[2]['content'][0];
        $this->assertStringContainsString('nunca diga que foi enviada', $result['content']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.drafts_suggested']);
        $this->assertStringNotContainsString('Ana', \DB::table('audit_entries')->where('action', 'ai.drafts_suggested')->value('metadata'));
    }

    public function test_several_options_in_one_answer(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([
            $this->draftCall('toolu_a', ['canal' => 'whatsapp', 'texto' => 'Opção curta']),
            $this->draftCall('toolu_b', ['canal' => 'email', 'texto' => 'Opção formal']),
        ])->pushText('Fiz duas opções.');

        $this->api()->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'me dá opções'])
            ->assertOk()
            ->assertJsonCount(2, 'drafts')
            ->assertJsonPath('drafts.1.channel', 'email');
    }

    public function test_draft_using_data_from_a_host_query_comes_back_after_tool_results(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([['id' => 'toolu_q', 'name' => 'financeiro_saldos_em_aberto', 'input' => ['periodo' => '2026-11']]])
            ->pushToolUse([$this->draftCall()])
            ->pushText('Segue a mensagem.');

        $this->api()->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'escreve cobrança gentil para quem mais deve'])
            ->assertJsonPath('status', 'tool_requested');

        $this->api()->postJson("/api/v1/conversations/{$id}/tool-results", ['results' => [['id' => 'toolu_q', 'output' => [['cliente' => 'Ana', 'em_aberto' => 320]]]]])
            ->assertOk()
            ->assertJsonPath('status', 'answered')
            ->assertJsonCount(1, 'drafts');
    }

    public function test_invalid_draft_goes_back_to_the_model_as_error(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->draftCall(input: ['canal' => 'telegrama', 'texto' => 'x'])])->pushText('Não consegui.');

        $this->api()->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'mensagem'])->assertOk()->assertJsonPath('drafts', []);
        $this->assertTrue($this->ai->lastRequest()->messages[2]['content'][0]['is_error']);
    }

    public function test_without_writing_capability_the_tool_is_not_offered(): void
    {
        $this->plan('ia-3000');
        $id = $this->open();
        $this->api()->postJson("/api/v1/conversations/{$id}/messages", ['text' => 'escreve um lembrete'])->assertOk();

        $request = $this->ai->lastRequest();
        $this->assertSame(['foxy_encaminhar_suporte'], array_column($request->tools, 'name'));
        $this->assertStringContainsString('você não escreve mensagens', $request->system);

        $this->plan('ia-5000');
        $this->open(['allowed_capabilities' => ['suporte', 'dados']]);
        $this->assertNotContains('foxy_sugerir_mensagem', array_column($this->ai->lastRequest()->tools, 'name'));
    }
}
