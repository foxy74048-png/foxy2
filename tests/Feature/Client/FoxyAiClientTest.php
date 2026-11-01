<?php

namespace Tests\Feature\Client;

use Foxy\AiClient\FoxyAiClient;
use Foxy\AiClient\HttpTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O pacote que os sistemas Foxy usam para falar com a Foxy AI por HTTP (packages/foxy-ai-client).
 */
class FoxyAiClientTest extends TestCase
{
    private function client(): FoxyAiClient
    {
        return (new FoxyAiClient(new HttpTransport('https://ai.foxy.test/api/v1', 'fxy_chave-do-sistema')))
            ->forOrganization('org-42')
            ->withToolLabels(['financeiro_saldos' => 'Financeiro']);
    }

    private function usage(float $cost): array
    {
        return ['model' => 'claude-haiku-5-5', 'input_tokens' => 10, 'output_tokens' => 5, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => $cost];
    }

    public function test_full_question_with_a_host_query(): void
    {
        Http::fakeSequence('ai.foxy.test/*')
            ->push(['status' => 'tool_requested', 'tool_calls' => [['id' => 't1', 'name' => 'financeiro_saldos', 'input' => ['periodo' => '2026-11']]], 'usage' => $this->usage(0.001)])
            ->push(['status' => 'answered', 'reply' => 'A Ana deve R$ 320.', 'escalate' => null, 'drafts' => [], 'usage' => $this->usage(0.002)]);

        $calls = [];
        $answer = $this->client()->ask('conv-1', 'Quem me deve?', function (string $tool, array $input) use (&$calls) {
            $calls[] = [$tool, $input];

            return [['cliente' => 'Ana', 'em_aberto' => 320]];
        }, 'Financeiro');

        $this->assertSame('A Ana deve R$ 320.', $answer['reply']);
        $this->assertSame(['Financeiro'], $answer['tools_used']);
        $this->assertSame([['financeiro_saldos', ['periodo' => '2026-11']]], $calls);
        $this->assertEqualsWithDelta(0.003, $answer['usage']['cost_usd'], 1e-9);
        $this->assertNull($answer['error']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ai.foxy.test/api/v1/conversations/conv-1/messages'
            && $r->hasHeader('Authorization', 'Bearer fxy_chave-do-sistema')
            && $r->hasHeader('X-Foxy-Organization', 'org-42')
            && $r['page'] === 'Financeiro');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/tool-results')
            && $r['results'][0]['output'][0]['cliente'] === 'Ana');
    }

    public function test_query_failure_goes_back_to_the_ai_as_error(): void
    {
        Http::fakeSequence('ai.foxy.test/*')
            ->push(['status' => 'tool_requested', 'tool_calls' => [['id' => 't1', 'name' => 'financeiro_saldos', 'input' => []]], 'usage' => $this->usage(0)])
            ->push(['status' => 'answered', 'reply' => 'Não consegui consultar.', 'usage' => $this->usage(0)]);

        $this->client()->ask('conv-1', 'oi', fn () => throw new \RuntimeException('sem permissão'));

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/tool-results')
            && $r['results'][0]['is_error'] === true
            && str_contains($r['results'][0]['output'], 'sem permissão'));
    }

    public function test_api_error_and_connection_failure_become_widget_errors(): void
    {
        Http::fake(['ai.foxy.test/*' => Http::response(['error' => ['code' => 'credit_limit', 'message' => 'Os créditos acabaram.']], 429)]);
        $answer = $this->client()->ask('conv-1', 'oi', fn () => null);
        $this->assertSame('credit_limit', $answer['error']['code']);
        $this->assertNull($answer['reply']);

        Http::fake(['ai.foxy.test/*' => fn () => throw new ConnectionException('fora do ar')]);
        $this->assertSame('ai_unreachable', $this->client()->ask('conv-1', 'oi', fn () => null)['error']['code']);
    }

    public function test_system_level_calls_do_not_send_the_client_header(): void
    {
        Http::fake(['ai.foxy.test/*' => Http::response(['id' => 'org-9'], 201)]);

        $this->client()->upsertOrganization('Clínica Nova', 'workspace-9');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/organizations') && ! $r->hasHeader('X-Foxy-Organization'));
    }
}
