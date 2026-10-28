<?php

namespace Tests\Feature\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ProviderUnavailable;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Confere o que é enviado à Anthropic, com um transporte HTTP simulado (sem rede).
 */
class AnthropicProviderTest extends TestCase
{
    private function transporter(int $status, array $body): ClientInterface
    {
        return new class($status, $body) implements ClientInterface
        {
            public ?RequestInterface $request = null;

            public function __construct(private int $status, private array $body) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response($this->status, ['Content-Type' => 'application/json'], json_encode($this->body));
            }
        };
    }

    private function okBody(): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Olá!'],
            ],
            'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 50, 'output_tokens' => 7, 'cache_read_input_tokens' => 4000, 'cache_creation_input_tokens' => 0],
        ];
    }

    private function request(array $tools = [], bool $allowTools = true): ModelRequest
    {
        return new ModelRequest('claude-opus-5-5', 'low', 8000, 'REGRAS', $tools, [['role' => 'user', 'content' => 'oi']], $allowTools);
    }

    public function test_sends_cached_rules_effort_and_refusal_fallback(): void
    {
        $http = $this->transporter(200, $this->okBody());
        $provider = new AnthropicProvider('test-key', 'default', 30, $http);

        $response = $provider->send($this->request());

        $body = json_decode((string) $http->request->getBody(), true);
        $this->assertSame('claude-opus-5-5', $body['model']);
        $this->assertSame([['type' => 'text', 'text' => 'REGRAS', 'cache_control' => ['type' => 'ephemeral']]], $body['system']);
        $this->assertSame(['type' => 'ephemeral'], $body['cache_control']);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertArrayNotHasKey('tools', $body);
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $http->request->getHeaderLine('anthropic-beta'));
        $this->assertSame('Bearer test-key', $http->request->getHeaderLine('Authorization'));

        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame('Olá!', $response->text());
        $this->assertSame(4000, $response->cacheReadTokens);
        $this->assertSame(['type' => 'thinking', 'signature' => 'sig', 'thinking' => ''], $response->content[0]);
    }

    public function test_tools_are_always_sent_and_can_be_disabled_with_tool_choice_none(): void
    {
        $tool = ['name' => 'financeiro_saldos', 'description' => 'Saldos', 'input_schema' => ['type' => 'object', 'properties' => ['periodo' => ['type' => 'string']]]];
        $http = $this->transporter(200, $this->okBody());

        (new AnthropicProvider('k', null, 30, $http))->send($this->request([$tool], allowTools: false));

        $body = json_decode((string) $http->request->getBody(), true);
        $this->assertSame('financeiro_saldos', $body['tools'][0]['name']);
        $this->assertSame(['type' => 'none'], $body['tool_choice']);
        $this->assertArrayNotHasKey('fallbacks', $body);
    }

    public function test_api_error_becomes_provider_unavailable(): void
    {
        $http = $this->transporter(400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'ruim']]);

        $this->expectException(ProviderUnavailable::class);
        (new AnthropicProvider('k', null, 30, $http))->send($this->request());
    }
}
