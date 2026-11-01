<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Quem mais me deve este mês?": a IA pede uma tool, o anfitrião executa e devolve, a IA responde.
 */
class DataQuestionsTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->organizationWithKey();
        $this->createProfile($this->key);

        $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo/tools', ['tools' => [
            ToolCatalogApiTest::validTool(),
            ToolCatalogApiTest::validTool([
                'name' => 'agenda_atendimentos',
                'description' => 'Atendimentos do período.',
                'input_schema' => ['type' => 'object', 'properties' => []],
            ]),
        ]])->assertOk();
    }

    private function open(array $allowed = ['financeiro_saldos_em_aberto', 'agenda_atendimentos']): string
    {
        return $this->withToken($this->key)
            ->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1', 'allowed_tools' => $allowed])
            ->assertCreated()
            ->json('conversation_id');
    }

    private function ask(string $id, string $text = 'Quem mais me deve este mês?')
    {
        return $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/messages", ['text' => $text]);
    }

    private function results(string $id, array $results)
    {
        return $this->withToken($this->key)->postJson("/api/v1/conversations/{$id}/tool-results", ['results' => $results]);
    }

    private function debtCall(array $input = ['periodo' => '2026-10', 'ordenar' => 'maior_valor', 'limite' => 5], string $id = 'toolu_1'): array
    {
        return ['id' => $id, 'name' => 'financeiro_saldos_em_aberto', 'input' => $input];
    }

    public function test_full_flow_who_owes_me_the_most_this_month(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()], 'Vou consultar.')
            ->pushText('Quem mais deve este mês é a Ana (R$ 320,00), seguida do Bruno (R$ 150,00).');

        $this->ask($id)
            ->assertOk()
            ->assertJsonPath('status', 'tool_requested')
            ->assertJsonPath('tool_calls.0.id', 'toolu_1')
            ->assertJsonPath('tool_calls.0.name', 'financeiro_saldos_em_aberto')
            ->assertJsonPath('tool_calls.0.input.periodo', '2026-10')
            ->assertJsonMissingPath('reply');

        $this->results($id, [['id' => 'toolu_1', 'output' => [
            ['cliente' => 'Ana', 'em_aberto' => 320.00],
            ['cliente' => 'Bruno', 'em_aberto' => 150.00],
        ]]])
            ->assertOk()
            ->assertJsonPath('status', 'answered')
            ->assertJsonPath('reply', 'Quem mais deve este mês é a Ana (R$ 320,00), seguida do Bruno (R$ 150,00).');

        $last = $this->ai->lastRequest()->messages;
        $this->assertCount(3, $last);
        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '[{"cliente":"Ana","em_aberto":320},{"cliente":"Bruno","em_aberto":150}]'], $last[2]['content'][0]);

        $this->assertDatabaseCount('conversation_messages', 4);
        $this->assertDatabaseHas('usage_counters', ['subject_ref' => 'u-1', 'quantity' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tool_requested']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tool_results_received']);
    }

    public function test_model_only_sees_tools_allowed_for_this_user(): void
    {
        $id = $this->open(['agenda_atendimentos']);
        $this->ask($id, 'oi')->assertOk();

        $this->assertSame(['agenda_atendimentos', 'foxy_encaminhar_suporte', 'foxy_sugerir_mensagem'], array_column($this->ai->lastRequest()->tools, 'name'));
    }

    public function test_opening_with_tool_outside_catalog_fails(): void
    {
        $this->withToken($this->key)
            ->postJson('/api/v1/conversations', ['host_profile' => 'agenda-demo', 'user_ref' => 'u-1', 'allowed_tools' => ['apagar_clientes']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'unknown_tools');
    }

    public function test_tool_not_allowed_never_reaches_the_host(): void
    {
        $id = $this->open(['agenda_atendimentos']);
        $this->ai->pushToolUse([$this->debtCall()])->pushText('Você não tem acesso ao financeiro.');

        $this->ask($id)->assertOk()->assertJsonPath('status', 'answered');

        $this->assertTrue($this->ai->lastRequest()->messages[2]['content'][0]['is_error']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tool_rejected']);
    }

    public function test_invalid_input_is_returned_to_the_model_without_bothering_the_host(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall(['periodo' => '2026-10', 'limite' => 'cinco', 'extra' => 1])])
            ->pushToolUse([$this->debtCall(id: 'toolu_2')]);

        $this->ask($id)->assertOk()->assertJsonPath('status', 'tool_requested')->assertJsonPath('tool_calls.0.id', 'toolu_2');

        $error = $this->ai->lastRequest()->messages[2]['content'][0];
        $this->assertTrue($error['is_error']);
        $this->assertStringContainsString('limite: deve ser integer', $error['content']);
        $this->assertStringContainsString('Campo desconhecido: extra', $error['content']);
    }

    public function test_tool_without_parameters_is_sent_to_host_with_empty_object(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([['id' => 'toolu_a', 'name' => 'agenda_atendimentos', 'input' => []]]);

        $response = $this->ask($id, 'Como está minha agenda?')->assertOk();

        $this->assertStringContainsString('"input":{}', $response->getContent());
    }

    public function test_new_message_while_waiting_for_results_is_rejected(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()]);
        $this->ask($id)->assertOk();

        $this->ask($id, 'outra coisa')->assertStatus(409)->assertJsonPath('error.code', 'tool_results_pending');
    }

    public function test_results_must_match_the_pending_calls(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()]);
        $this->ask($id)->assertOk();

        $this->results($id, [['id' => 'toolu_x', 'output' => []]])->assertStatus(422)->assertJsonPath('error.code', 'tool_results_mismatch');
        $this->results($id, [['id' => 'toolu_1', 'output' => []], ['id' => 'toolu_1', 'output' => []]])->assertStatus(422);

        // Continua aguardando: a resposta certa ainda funciona.
        $this->ai->pushText('Ninguém deve nada este mês.');
        $this->results($id, [['id' => 'toolu_1', 'output' => []]])->assertOk()->assertJsonPath('reply', 'Ninguém deve nada este mês.');
    }

    public function test_results_without_pending_calls(): void
    {
        $id = $this->open();

        $this->results($id, [['id' => 'toolu_1', 'output' => []]])->assertStatus(409)->assertJsonPath('error.code', 'no_pending_tools');
    }

    public function test_host_error_is_passed_to_the_model_as_error(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()])->pushText('Não consegui consultar agora.');
        $this->ask($id)->assertOk();

        $this->results($id, [['id' => 'toolu_1', 'output' => 'Sem permissão', 'is_error' => true]])->assertOk();

        $this->assertSame(['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => 'Sem permissão', 'is_error' => true], $this->ai->lastRequest()->messages[2]['content'][0]);
    }

    public function test_host_tool_and_escalation_in_the_same_turn(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([
            $this->debtCall(),
            ['id' => 'toolu_s', 'name' => 'foxy_encaminhar_suporte', 'input' => ['motivo' => 'pedido_da_pessoa', 'resumo' => 'Quer revisar cobranças.']],
        ])->pushText('Aqui estão os valores, e encaminhei ao suporte.');
        $this->ask($id)->assertOk()->assertJsonCount(1, 'tool_calls');

        $this->results($id, [['id' => 'toolu_1', 'output' => []]])
            ->assertOk()
            ->assertJsonPath('escalate.reason', 'pedido_da_pessoa');

        $blocks = $this->ai->lastRequest()->messages[2]['content'];
        $this->assertSame(['toolu_1', 'toolu_s'], array_column($blocks, 'tool_use_id'));
    }

    public function test_provider_failure_after_results_keeps_waiting_and_can_be_retried(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()])->push(new ProviderUnavailable('caiu'))->pushText('Pronto.');
        $this->ask($id)->assertOk();

        $this->results($id, [['id' => 'toolu_1', 'output' => []]])->assertStatus(503);
        $this->assertDatabaseCount('conversation_messages', 2);

        $this->results($id, [['id' => 'toolu_1', 'output' => []]])->assertOk()->assertJsonPath('reply', 'Pronto.');
    }

    public function test_result_too_large_is_rejected(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()]);
        $this->ask($id)->assertOk();

        $this->results($id, [['id' => 'toolu_1', 'output' => str_repeat('x', 30_001)]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'tool_result_too_large');
    }

    public function test_other_organization_cannot_send_results(): void
    {
        $id = $this->open();
        $this->ai->pushToolUse([$this->debtCall()]);
        $this->ask($id)->assertOk();
        [, $otherKey] = $this->organizationWithKey('Outra');

        $this->withToken($otherKey)->postJson("/api/v1/conversations/{$id}/tool-results", ['results' => [['id' => 'toolu_1', 'output' => []]]])
            ->assertNotFound();
    }
}
