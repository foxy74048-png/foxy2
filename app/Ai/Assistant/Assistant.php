<?php

namespace App\Ai\Assistant;

use App\Ai\Conversations\Conversation;
use App\Ai\HostProfiles\HostProfile;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ModelResponse;
use App\Ai\Providers\ProviderUnavailable;
use App\Ai\Usage\UsageRecord;
use App\Platform\Audit\Audit;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Entitlements\Entitlements;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Orquestra a conversa: contexto → modelo → (tools) → resposta. Ver docs/02-foxy-ai.md (seção 6)
 * e docs/05-arquitetura.md (seção 5).
 */
class Assistant
{
    public const METER = 'ai.messages';

    private const REFUSAL_REPLY = 'Não consigo ajudar com esse pedido. Se precisar, posso encaminhar você para o suporte.';

    public function __construct(
        private ModelProvider $provider,
        private Entitlements $entitlements,
        private ContextStore $contexts,
    ) {}

    public function open(HostProfile $profile, string $userRef): Conversation
    {
        $knowledge = $profile->currentKnowledge() ?? throw AssistantException::knowledgeMissing();

        $conversation = Conversation::create([
            'host_profile_id' => $profile->getKey(),
            'user_ref' => $userRef,
            'model' => config('foxy.ai.model'),
            'effort' => config('foxy.ai.effort'),
            'system_prompt' => SystemPrompt::render($profile, $knowledge),
            'tools' => InternalTools::definitions(),
            'allowed_tools' => [],
            'knowledge_version' => $knowledge->version,
            'tools_version' => $profile->current_tools_version,
            'status' => Conversation::AWAITING_USER,
            'expires_at' => now()->addDays(config('foxy.ai.conversation_retention_days')),
        ]);

        $this->asAgent($conversation, fn () => Audit::record('ai.conversation_opened', 'ok', $conversation, [
            'host_profile' => $profile->slug,
            'knowledge_version' => $knowledge->version,
            'model' => $conversation->model,
        ]));

        return $conversation;
    }

    /**
     * @param  array{message: string, code?: ?string, action?: ?string}|null  $error  erro mostrado na tela do anfitrião
     */
    public function reply(Conversation $conversation, string $text, ?string $page = null, ?array $error = null): AssistantResult
    {
        return $this->asAgent($conversation, function () use ($conversation, $text, $page, $error) {
            $this->guardCanReceiveMessage($conversation);

            if (! $conversation->claim(Conversation::AWAITING_USER)) {
                throw AssistantException::conversationBusy();
            }

            $userMessage = ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $this->contextBlock($page, $error)],
                ['type' => 'text', 'text' => $text],
            ]];

            return $this->runTurn($conversation, [$userMessage], Conversation::AWAITING_USER);
        });
    }

    /**
     * Chama o modelo até ele responder em texto. Tools internas e pedidos inválidos são resolvidos aqui;
     * nada é gravado no histórico antes de a rodada terminar com sucesso.
     */
    private function runTurn(Conversation $conversation, array $newMessages, string $statusOnFailure): AssistantResult
    {
        $usage = [];
        $escalate = null;
        $maxRounds = config('foxy.ai.max_tool_rounds_per_message');

        try {
            while (true) {
                $response = $this->send($conversation, [...$conversation->history(), ...$newMessages], $conversation->tool_rounds < $maxRounds);
                $usage[] = $record = UsageRecord::fromResponse($response, $conversation->getKey());
                $this->entitlements->recordUsage(self::METER, $conversation->user_ref, 0, $record->cost_usd);

                $newMessages[] = ['role' => 'assistant', 'content' => $this->assistantContent($response)];
                $calls = $response->stopReason === 'tool_use' ? $response->toolUses() : [];

                if ($calls === []) {
                    break;
                }

                $results = [];
                foreach ($calls as $call) {
                    if (InternalTools::isInternal($call['name'])) {
                        [$results[], $escalated] = InternalTools::run($call);
                        $escalate ??= $escalated;
                    } else {
                        $results[] = ToolResults::error($call['id'], 'Ferramenta não disponível nesta conversa.');
                        Audit::record('ai.tool_rejected', 'rejected', $conversation, ['tool' => $call['name']]);
                    }
                }

                $newMessages[] = ['role' => 'user', 'content' => $results];
                $conversation->tool_rounds++;
            }
        } catch (ProviderUnavailable) {
            $conversation->refresh()->release($statusOnFailure, $conversation->pending_tool_calls);

            throw AssistantException::providerUnavailable();
        }

        $conversation->appendToHistory(...$newMessages);
        $conversation->forceFill(['tool_rounds' => 0, 'user_messages' => $conversation->user_messages + 1]);
        $conversation->release(Conversation::AWAITING_USER);
        $this->entitlements->recordUsage(self::METER, $conversation->user_ref, 1);

        $refused = $response->stopReason === 'refusal';
        $reply = $refused && $response->text() === '' ? self::REFUSAL_REPLY : $response->text();

        if ($escalate) {
            Audit::record('ai.escalated', 'ok', $conversation, ['reason' => $escalate['reason']]);
        }

        Audit::record('ai.message_answered', $refused ? 'refused' : 'ok', $conversation, [
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
            'model_calls' => count($usage),
        ]);

        return new AssistantResult($conversation, AssistantResult::ANSWERED, $reply, $usage, escalate: $escalate);
    }

    private function guardCanReceiveMessage(Conversation $conversation): void
    {
        if ($conversation->expires_at->isPast()) {
            throw AssistantException::conversationExpired();
        }

        $max = config('foxy.ai.max_user_messages_per_conversation');
        if ($conversation->user_messages >= $max) {
            throw AssistantException::conversationFull($max);
        }

        $key = 'ai-user:'.$conversation->organization_id.':'.$conversation->user_ref;
        if (RateLimiter::tooManyAttempts($key, config('foxy.ai.user_messages_per_minute'))) {
            throw AssistantException::userRateLimited();
        }
        RateLimiter::hit($key, 60);

        if (! $this->entitlements->canUse(self::METER, $conversation->user_ref)) {
            throw AssistantException::usageLimit();
        }
    }

    private function send(Conversation $conversation, array $messages, bool $allowTools): ModelResponse
    {
        return $this->provider->send(new ModelRequest(
            model: $conversation->model,
            effort: $conversation->effort,
            maxTokens: config('foxy.ai.max_tokens'),
            system: $conversation->system_prompt,
            tools: $conversation->tools,
            messages: $messages,
            allowTools: $allowTools,
        ));
    }

    /**
     * Data, tela e erro vão em cada mensagem (e não nas regras fixas), para não invalidar o cache.
     */
    private function contextBlock(?string $page, ?array $error): string
    {
        $today = now()->locale('pt_BR');
        $lines = ['Data de hoje: '.$today->isoFormat('dddd, D [de] MMMM [de] YYYY').' ('.$today->toDateString().')'];

        if ($page !== null && trim($page) !== '') {
            $lines[] = 'Tela atual: '.trim($page);
        }

        if ($error !== null) {
            $code = trim((string) ($error['code'] ?? ''));
            $lines[] = 'Erro mostrado na tela: '.trim($error['message']).($code !== '' ? " (código {$code})" : '');

            $action = trim((string) ($error['action'] ?? ''));
            if ($action !== '') {
                $lines[] = 'O que a pessoa tentava fazer: '.$action;
            }
        }

        return "<contexto>\n".implode("\n", $lines)."\n</contexto>";
    }

    /**
     * Conteúdo do assistente como veio do provedor; se vier vazio (ex.: recusa), grava a resposta padrão.
     */
    private function assistantContent(ModelResponse $response): array
    {
        $hasVisibleBlock = collect($response->content)->contains(fn ($b) => in_array($b['type'] ?? null, ['text', 'tool_use'], true));

        if ($hasVisibleBlock) {
            return $response->content;
        }

        return [...$response->content, ['type' => 'text', 'text' => self::REFUSAL_REPLY]];
    }

    private function asAgent(Conversation $conversation, callable $callback): mixed
    {
        return $this->contexts->run(ExecutionContext::current()->asAiAgentFor($conversation->user_ref), $callback);
    }
}
