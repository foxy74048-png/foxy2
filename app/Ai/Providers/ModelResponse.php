<?php

namespace App\Ai\Providers;

final readonly class ModelResponse
{
    /**
     * @param  list<array>  $content  blocos no formato da API, para guardar e reenviar sem alteração
     */
    public function __construct(
        public string $model,
        public string $stopReason,
        public array $content,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    public function text(): string
    {
        $parts = array_map(
            fn (array $block) => $block['text'],
            array_filter($this->content, fn (array $block) => ($block['type'] ?? null) === 'text'),
        );

        return trim(implode("\n\n", $parts));
    }

    /**
     * @return list<array{id: string, name: string, input: array}>
     */
    public function toolUses(): array
    {
        return array_values(array_map(
            fn (array $block) => ['id' => $block['id'], 'name' => $block['name'], 'input' => (array) ($block['input'] ?? [])],
            array_filter($this->content, fn (array $block) => ($block['type'] ?? null) === 'tool_use'),
        ));
    }
}
