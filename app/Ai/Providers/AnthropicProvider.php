<?php

namespace App\Ai\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;

class AnthropicProvider implements ModelProvider
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private Client $client;

    /**
     * @param  ClientInterface|null  $transporter  só para testes (resposta simulada, sem rede)
     */
    public function __construct(
        string $apiKey,
        private ?string $refusalFallbacks = 'default',
        float $timeoutSeconds = 90,
        ?ClientInterface $transporter = null,
    ) {
        $options = ['timeout' => $timeoutSeconds, 'maxRetries' => 2];

        if ($transporter) {
            $options['transporter'] = $transporter;
        }

        $this->client = new Client(apiKey: $apiKey, requestOptions: $options);
    }

    public function send(ModelRequest $request): ModelResponse
    {
        $params = [
            'model' => $request->model,
            'maxTokens' => $request->maxTokens,
            // Ordem fixa: tools → system (ponto de cache) → histórico (cache automático no fim).
            'system' => [['type' => 'text', 'text' => $request->system, 'cache_control' => ['type' => 'ephemeral']]],
            'cacheControl' => ['type' => 'ephemeral'],
            'outputConfig' => ['effort' => $request->effort],
            'messages' => array_map(self::restoreEmptyToolInputs(...), $request->messages),
        ];

        if ($request->tools !== []) {
            $params['tools'] = array_map(self::restoreEmptyProperties(...), $request->tools);

            if (! $request->allowTools) {
                $params['toolChoice'] = ['type' => 'none'];
            }
        }

        if ($this->refusalFallbacks) {
            $params['fallbacks'] = $this->refusalFallbacks;
            $params['betas'] = [self::FALLBACK_BETA];
        }

        try {
            $message = $this->client->beta->messages->create(...$params);
        } catch (APIException $e) {
            Log::error('Falha ao chamar a Anthropic', ['exception' => $e::class, 'message' => $e->getMessage()]);

            throw new ProviderUnavailable('O provedor de IA não respondeu.', previous: $e);
        }

        return new ModelResponse(
            model: (string) $message->model,
            stopReason: (string) $message->stopReason,
            content: json_decode(json_encode($message->content), true),
            inputTokens: (int) $message->usage->inputTokens,
            outputTokens: (int) $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }

    /**
     * O histórico volta do banco como array PHP, onde {} vira []; a API exige objeto em tool_use.input.
     */
    private static function restoreEmptyToolInputs(array $message): array
    {
        if (! is_array($message['content'])) {
            return $message;
        }

        foreach ($message['content'] as $i => $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? []) === []) {
                $message['content'][$i]['input'] = new \stdClass;
            }
        }

        return $message;
    }

    private static function restoreEmptyProperties(array $tool): array
    {
        if (($tool['input_schema']['properties'] ?? []) === []) {
            $tool['input_schema']['properties'] = new \stdClass;
        }

        return $tool;
    }
}
