<?php

namespace App\Ai\Assistant;

use App\Ai\Conversations\Conversation;
use App\Ai\Usage\UsageRecord;
use App\Platform\Context\ExecutionContext;

final readonly class AssistantResult
{
    public const ANSWERED = 'answered';

    public const TOOL_REQUESTED = 'tool_requested';

    /**
     * @param  list<UsageRecord>  $usage
     */
    public function __construct(
        public Conversation $conversation,
        public string $status,
        public ?string $reply,
        public array $usage,
        public array $toolCalls = [],
        public ?array $escalate = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'conversation_id' => $this->conversation->getKey(),
            'status' => $this->status,
        ];

        if ($this->status === self::ANSWERED) {
            $data['reply'] = $this->reply;
            $data['escalate'] = $this->escalate;
        } else {
            $data['tool_calls'] = $this->toolCalls;
        }

        $data['usage'] = [
            'model' => $this->usage === [] ? $this->conversation->model : $this->usage[array_key_last($this->usage)]->model,
            'input_tokens' => array_sum(array_map(fn ($u) => $u->input_tokens, $this->usage)),
            'output_tokens' => array_sum(array_map(fn ($u) => $u->output_tokens, $this->usage)),
            'cache_read_tokens' => array_sum(array_map(fn ($u) => $u->cache_read_tokens, $this->usage)),
            'cache_write_tokens' => array_sum(array_map(fn ($u) => $u->cache_write_tokens, $this->usage)),
            'cost_usd' => round(array_sum(array_map(fn ($u) => $u->cost_usd, $this->usage)), 6),
        ];
        $data['request_id'] = ExecutionContext::current()->correlationId;

        return $data;
    }
}
