<?php

namespace Tests\Feature\Ai;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ToolCatalogApiTest extends TestCase
{
    use InteractsWithFoxyAi, RefreshDatabase;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->key] = $this->organizationWithKey();
        $this->createProfile($this->key);
    }

    public static function validTool(array $overrides = []): array
    {
        return array_replace([
            'name' => 'financeiro_saldos_em_aberto',
            'description' => 'Clientes com valor em aberto no período.',
            'risk' => 'read',
            'permission' => 'financeiro.ver',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'periodo' => ['type' => 'string', 'description' => 'Mês no formato AAAA-MM'],
                    'ordenar' => ['type' => 'string', 'enum' => ['maior_valor', 'mais_antigo']],
                    'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ],
                'required' => ['periodo'],
            ],
        ], $overrides);
    }

    private function putCatalog(array $tools)
    {
        return $this->withToken($this->key)->putJson('/api/v1/host-profiles/agenda-demo/tools', ['tools' => $tools]);
    }

    public function test_publishes_versioned_catalog(): void
    {
        $this->putCatalog([self::validTool()])->assertOk()->assertJson(['version' => 1, 'changed' => true, 'tools' => ['financeiro_saldos_em_aberto']]);
        $this->putCatalog([self::validTool()])->assertOk()->assertJson(['version' => 1, 'changed' => false]);
        $this->putCatalog([])->assertOk()->assertJson(['version' => 2, 'changed' => true, 'tools' => []]);

        $this->withToken($this->key)->getJson('/api/v1/host-profiles/agenda-demo')->assertJsonPath('tools_version', 2);
        $this->assertDatabaseHas('audit_entries', ['action' => 'ai.tools_published']);
    }

    public function test_catalog_schema_is_closed_to_extra_fields(): void
    {
        $this->putCatalog([self::validTool()])->assertOk();

        $stored = \DB::table('tool_catalogs')->value('tools');
        $this->assertFalse(json_decode($stored, true)[0]['input_schema']['additionalProperties']);
    }

    public function test_rejects_invalid_catalogs_with_clear_messages(): void
    {
        $cases = [
            'nome com ponto' => [self::validTool(['name' => 'financeiro.saldos']), 'letras minúsculas'],
            'prefixo reservado' => [self::validTool(['name' => 'foxy_qualquer']), 'reservado'],
            'tool que altera dados' => [self::validTool(['risk' => 'write']), 'só são aceitas tools de leitura'],
            'sem descrição' => [self::validTool(['description' => '']), 'descrição obrigatória'],
            'palavra não suportada' => [self::validTool(['input_schema' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string', 'pattern' => '.*']]]]), 'não suportadas: pattern'],
            'required inexistente' => [self::validTool(['input_schema' => ['type' => 'object', 'properties' => [], 'required' => ['y']]]), 'required'],
            'schema não é objeto' => [self::validTool(['input_schema' => ['type' => 'string']]), 'input_schema'],
        ];

        foreach ($cases as $label => [$tool, $expected]) {
            $response = $this->putCatalog([$tool])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
            $this->assertStringContainsString($expected, implode(' ', $response->json('error.details.tools')), $label);
        }

        $this->putCatalog([self::validTool(), self::validTool()])->assertStatus(422);
        $this->assertDatabaseCount('tool_catalogs', 0);
    }
}
