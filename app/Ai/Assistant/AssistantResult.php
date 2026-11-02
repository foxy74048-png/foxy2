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
        public array $drafts = [],
        public ?array $featureRequest = null,
        public ?array $knowledgeGap = null,
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
            // Mensagens sugeridas para a pessoa revisar e copiar; a IA nunca envia.
            $data['drafts'] = $this->drafts;
            // Algo que a pessoa quis e o sistema ainda não tem: o anfitrião guarda para a equipe avaliar.
            $data['feature_request'] = $this->featureRequest;
            // Dúvida de uso que o conhecimento não cobre: o anfitrião guarda para completar o manual.
            $data['knowledge_gap'] = $this->knowledgeGap;
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
