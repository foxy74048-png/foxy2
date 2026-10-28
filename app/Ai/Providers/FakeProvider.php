<?php

namespace App\Ai\Providers;

/**
 * Provedor de mentira para testes e para rodar sem chave da Anthropic.
 */
class FakeProvider implements ModelProvider
{
    /** @var list<ModelResponse|\Throwable> */
    private array $queue = [];

    /** @var list<ModelRequest> */
    public array $requests = [];

    public function push(ModelResponse|\Throwable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function pushText(string $text, string $model = 'claude-opus-5-5'): self
    {
        return $this->push(new ModelResponse($model, 'end_turn', [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-'.count($this->queue)],
            ['type' => 'text', 'text' => $text],
        ], 1200, 150, 9000, 0));
    }

    /**
     * @param  list<array{id: string, name: string, input: array}>  $calls
     */
    public function pushToolUse(array $calls, string $text = ''): self
    {
        $content = [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig-'.count($this->queue)]];

        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($calls as $call) {
            $content[] = ['type' => 'tool_use'] + $call;
        }

        return $this->push(new ModelResponse('claude-opus-5-5', 'tool_use', $content, 1300, 80, 9000, 0));
    }

    public function send(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next ?? new ModelResponse($request->model, 'end_turn', [
            ['type' => 'text', 'text' => 'Resposta de teste.'],
        ], 100, 10);
    }

    public function lastRequest(): ModelRequest
    {
        if ($this->requests === []) {
            throw new \LogicException('Nenhuma chamada ao provedor.');
        }

        return $this->requests[array_key_last($this->requests)];
    }
}
