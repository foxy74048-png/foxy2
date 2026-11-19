<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\ProviderUnavailable;
use App\Ai\Usage\UsageRecord;
use App\Platform\ApiClients\ApiClient;
use App\Platform\Audit\AuditEntry;
use App\Platform\Organizations\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarefa avulsa da Foxy AI (docs/12 §14, R-1): só tarefas do catálogo, entrada validada, resposta presa a um
 * esquema JSON (saída estruturada), mesmo crédito da conversa, registro do uso sem o conteúdo.
 */
class TaskApiTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeAi();
    }

    private function categorizeInput(): array
    {
        return [
            'items' => [['index' => 0, 'description' => 'IFOOD *PEDIDO 991', 'kind' => 'gasto'], ['index' => 1, 'description' => 'PIX JOAO 123', 'kind' => 'gasto']],
            'categories' => [['id' => 'c1', 'name' => 'Comer fora › Delivery', 'kind' => 'gasto'], ['id' => 'c2', 'name' => 'Mercado', 'kind' => 'gasto']],
        ];
    }

    private function modelJson(array $data): ModelResponse
    {
        return new ModelResponse('claude-haiku-5-5', 'end_turn', [['type' => 'text', 'text' => json_encode($data)]], 400, 60);
    }

    public function test_roda_a_tarefa_com_o_modelo_rapido_e_a_resposta_presa_ao_esquema(): void
    {
        [$organization, $key] = $this->organizationWithKey('Carteira', ['ai.tasks']);
        $this->ai->push($this->modelJson(['items' => [['index' => 0, 'category_id' => 'c1'], ['index' => 1, 'category_id' => null]]]));

        $response = $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.categorize', 'input' => $this->categorizeInput()])->assertOk();

        $this->assertSame([['index' => 0, 'category_id' => 'c1'], ['index' => 1, 'category_id' => null]], $response->json('output.items'));
        $this->assertSame('claude-haiku-5-5', $response->json('usage.model'));

        $request = $this->ai->lastRequest();
        $this->assertSame(config('foxy.ai.tiers.rapida'), $request->model);
        $this->assertSame([], $request->tools);
        $this->assertSame(['items'], $request->outputSchema['required']);
        $this->assertStringContainsString('categoriza movimentos de extrato', $request->system);
        $this->assertStringContainsString('IFOOD', $request->messages[0]['content']);

        // Uso registrado (para o crédito e o Foxy Admin), sem o conteúdo da tarefa.
        $this->assertSame(1, UsageRecord::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $audit = AuditEntry::withoutGlobalScopes()->where('action', 'ai.task_run')->firstOrFail();
        $this->assertStringNotContainsString('IFOOD', json_encode($audit->toArray()));
    }

    public function test_so_tarefas_do_catalogo_e_entrada_valida(): void
    {
        [, $key] = $this->organizationWithKey('Carteira', ['ai.tasks']);

        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'escreva.um.poema', 'input' => ['x' => 1]])->assertNotFound()->assertJsonPath('error.code', 'unknown_task');
        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.categorize', 'input' => ['items' => []]])->assertStatus(422)->assertJsonPath('error.code', 'invalid_input');
        $this->assertSame([], $this->ai->requests, 'Nada chega ao modelo.');
    }

    public function test_exige_a_permissao_e_o_cliente(): void
    {
        [, $key] = $this->organizationWithKey('Carteira', ['ai.conversations']);
        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.categorize', 'input' => $this->categorizeInput()])->assertForbidden();
    }

    public function test_sem_credito_nao_roda(): void
    {
        [$integration, $systemKey] = $this->integrationWithKey('Foxy Finanças');
        $organization = Organization::create(['name' => 'Carteira', 'integration_id' => $integration->id]);
        [, $key] = ApiClient::issue($integration, 'Carteira', ['ai.tasks'], $organization);
        $this->withToken($systemKey)->putJson("/api/v1/organizations/{$organization->id}/ai-subscription", ['plan' => 'ia-0'])->assertSuccessful();

        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.categorize', 'input' => $this->categorizeInput()])->assertJsonPath('error.code', 'credit_limit');
        $this->assertSame([], $this->ai->requests);
    }

    public function test_resposta_que_nao_e_json_ou_ia_fora_do_ar(): void
    {
        [, $key] = $this->organizationWithKey('Carteira', ['ai.tasks']);
        $this->ai->pushText('não sei');
        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.tips', 'input' => ['month' => 'novembro de 2026', 'facts' => ['Saiu R$ 10,00']]])->assertStatus(502)->assertJsonPath('error.code', 'task_failed');

        $this->ai->push(new ProviderUnavailable('fora'));
        $this->withToken($key)->postJson('/api/v1/tasks', ['task' => 'finance.tips', 'input' => ['month' => 'novembro de 2026', 'facts' => ['Saiu R$ 10,00']]])->assertJsonPath('error.code', 'ai_unavailable');
    }
}
