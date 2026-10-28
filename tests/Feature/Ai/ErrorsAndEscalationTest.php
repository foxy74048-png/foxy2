<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\ProviderUnavailable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorsAndEscalationTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
        [, $this->key] = $this->organizationWithKey();
        $this->createProfile($this->key);
        $this->id = $this->openConversation($this->key);
    }

    private function send(array $payload)
    {
        return $this->withToken($this->key)->postJson("/api/v1/conversations/{$this->id}/messages", $payload);
    }

    private function escalateCall(string $id = 'toolu_1', array $input = ['motivo' => 'defeito', 'resumo' => 'Erro AG-409 ao salvar atendimento em horário livre.']): array
    {
        return ['id' => $id, 'name' => 'foxy_encaminhar_suporte', 'input' => $input];
    }

    public function test_error_shown_on_screen_goes_in_the_message_context(): void
    {
        $this->ai->pushText('Esse erro aparece quando o horário já está ocupado.');

        $this->send([
            'text' => 'O que é isso?',
            'page' => 'Agenda › Novo atendimento',
            'error' => ['message' => 'Horário indisponível', 'code' => 'AG-409', 'action' => 'Salvar atendimento às 14h'],
        ])->assertOk()->assertJsonPath('escalate', null);

        $context = $this->ai->lastRequest()->messages[0]['content'][0]['text'];
        $this->assertStringContainsString('Erro mostrado na tela: Horário indisponível (código AG-409)', $context);
        $this->assertStringContainsString('O que a pessoa tentava fazer: Salvar atendimento às 14h', $context);
    }

    public function test_error_requires_message(): void
    {
        $this->send(['text' => 'oi', 'error' => ['code' => 'X']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_internal_support_tool_is_offered_to_the_model(): void
    {
        $this->send(['text' => 'oi'])->assertOk();

        $this->assertSame(['foxy_encaminhar_suporte'], array_column($this->ai->lastRequest()->tools, 'name'));
    }

    public function test_escalation_returns_summary_to_the_host(): void
    {
        $this->ai->pushToolUse([$this->escalateCall()])
            ->pushText('Encaminhei seu caso ao suporte.');

        $this->send(['text' => 'Deu erro de novo, quero falar com alguém'])
            ->assertOk()
            ->assertJsonPath('status', 'answered')
            ->assertJsonPath('reply', 'Encaminhei seu caso ao suporte.')
            ->assertJsonPath('escalate.reason', 'defeito')
            ->assertJsonPath('escalate.summary', 'Erro AG-409 ao salvar atendimento em horário livre.');

        // A segunda chamada leva o pedido da tool e o resultado, sem passar pelo anfitrião.
        $second = $this->ai->lastRequest()->messages;
        $this->assertCount(3, $second);
        $this->assertSame('tool_result', $second[2]['content'][0]['type']);
        $this->assertSame('toolu_1', $second[2]['content'][0]['tool_use_id']);

        $this->assertDatabaseCount('conversation_messages', 4);
        $this->assertDatabaseCount('usage_records', 2);
        $this->assertDatabaseHas('usage_counters', ['subject_ref' => 'u-1', 'quantity' => 1]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.escalated']);
    }

    public function test_invalid_escalation_input_is_returned_to_the_model_as_error(): void
    {
        $this->ai->pushToolUse([$this->escalateCall(input: ['motivo' => 'qualquer'])])
            ->pushText('Pode me contar mais?');

        $this->send(['text' => 'socorro'])->assertOk()->assertJsonPath('escalate', null);

        $result = $this->ai->lastRequest()->messages[2]['content'][0];
        $this->assertTrue($result['is_error']);
    }

    public function test_unknown_tool_is_rejected_and_audited(): void
    {
        $this->ai->pushToolUse([['id' => 'toolu_9', 'name' => 'apagar_tudo', 'input' => []]])
            ->pushText('Não consigo fazer isso.');

        $this->send(['text' => 'apague tudo'])->assertOk();

        $this->assertTrue($this->ai->lastRequest()->messages[2]['content'][0]['is_error']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tool_rejected', 'result' => 'rejected']);
    }

    public function test_after_round_limit_the_model_must_answer_in_text(): void
    {
        config(['foxy.ai.max_tool_rounds_per_message' => 1]);
        $this->ai->pushToolUse([$this->escalateCall()])->pushText('Pronto.');

        $this->send(['text' => 'oi'])->assertOk();

        $this->assertTrue($this->ai->requests[0]->allowTools);
        $this->assertFalse($this->ai->requests[1]->allowTools);
        // As tools continuam na requisição (mudar a lista quebraria o histórico); só ficam desligadas.
        $this->assertSame($this->ai->requests[0]->tools, $this->ai->requests[1]->tools);
    }

    public function test_failure_in_a_later_round_saves_nothing_but_keeps_the_real_cost(): void
    {
        $this->ai->pushToolUse([$this->escalateCall()])->push(new ProviderUnavailable('caiu'));

        $this->send(['text' => 'oi'])->assertStatus(503);

        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('usage_records', 1);
        $this->assertDatabaseHas('usage_counters', ['subject_ref' => 'u-1', 'quantity' => 0]);

        $this->ai->pushText('Agora sim.');
        $this->send(['text' => 'oi'])->assertOk()->assertJsonPath('reply', 'Agora sim.');
    }
}
