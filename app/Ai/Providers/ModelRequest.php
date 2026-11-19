<?php

namespace App\Ai\Providers;

/**
 * Uma chamada ao modelo. Mensagens e tools no formato da API de Messages (snake_case),
 * que também é o formato guardado no histórico.
 */
final readonly class ModelRequest
{
    /**
     * @param  list<array{name: string, description: string, input_schema: array}>  $tools
     * @param  list<array{role: string, content: mixed}>  $messages
     */
    public function __construct(
        public string $model,
        public string $effort,
        public int $maxTokens,
        public string $system,
        public array $tools,
        public array $messages,
        public bool $allowTools = true,
        // Tarefa avulsa (docs/12 §14): esquema JSON que a resposta tem que seguir (saída estruturada).
        public ?array $outputSchema = null,
    ) {}
}
