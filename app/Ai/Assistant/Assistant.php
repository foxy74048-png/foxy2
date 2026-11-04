<?php

namespace App\Ai\Assistant;

use App\Ai\Assistants\AssistantPreference;
use App\Ai\Insights\AiInsight;
use App\Ai\Conversations\Conversation;
use App\Ai\HostProfiles\HostProfile;
use App\Ai\HostProfiles\ToolSchema;
use App\Ai\Plans\AiAccount;
use App\Ai\Plans\AiPlan;
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
        private AiAccount $account,
    ) {}

    /**
     * @param  list<string>  $allowedTools  tools do catálogo que este usuário pode usar (o anfitrião filtra pelas permissões dele)
     * @param  string|null  $tier  faixa de modelo pedida (padrão: a do plano)
     * @param  list<string>|null  $userCapabilities  o que este usuário pode usar (null = tudo o que o plano permite)
     */
    public function open(HostProfile $profile, string $userRef, array $allowedTools = [], ?string $tier = null, ?array $userCapabilities = null): Conversation
    {
        $plan = $this->account->plan() ?? throw AssistantException::noAiPlan();
        $tier ??= $plan->defaultTier;

        // Sempre a menor permissão vence: plano ∩ usuário. Dúvidas de uso ("suporte") são a base de toda conversa.
        $capabilities = array_values(array_intersect($plan->capabilities, $userCapabilities ?? $plan->capabilities));
        if (! in_array('suporte', $capabilities, true)) {
            throw AssistantException::noCapabilities();
        }

        if (! $plan->allowsTier($tier)) {
            throw AssistantException::tierNotInPlan($tier, $plan);
        }

        $knowledge = $profile->currentKnowledge() ?? throw AssistantException::knowledgeMissing();
        $catalog = $profile->currentTools();
        $allowedTools = array_values(array_unique($allowedTools));

        $unknown = array_diff($allowedTools, $catalog?->names() ?? []);
        if ($unknown !== []) {
            throw AssistantException::unknownTools($unknown);
        }

        // Sem "dados" (no plano ou para este usuário): nenhuma consulta do sistema é oferecida.
        if (! in_array('dados', $capabilities, true)) {
            $allowedTools = [];
        }

        $conversation = Conversation::create([
            'host_profile_id' => $profile->getKey(),
            'user_ref' => $userRef,
            'tier' => $tier,
            'model' => AiPlan::modelFor($tier),
            'effort' => config('foxy.ai.effort'),
            'system_prompt' => SystemPrompt::render($profile, $knowledge, $capabilities, $fox = AssistantPreference::resolveFor($userRef)),
            'capabilities' => $capabilities,
            'assistant' => $fox['id'],
            'assistant_name' => $fox['name'],
            'assistant_visual' => $fox['visual'],
            'tools' => [...($catalog?->definitionsFor($allowedTools) ?? []), ...InternalTools::definitions($capabilities)],
            'allowed_tools' => $allowedTools,
            'knowledge_version' => $knowledge->version,
            'tools_version' => $profile->current_tools_version,
            'status' => Conversation::AWAITING_USER,
            // Histórico recente: o plano decide por quanto tempo a conversa fica guardada.
            'expires_at' => now()->addDays($plan->retentionDays),
        ]);

        $this->asAgent($conversation, fn () => Audit::record('ai.conversation_opened', 'ok', $conversation, [
            'host_profile' => $profile->slug,
            'knowledge_version' => $knowledge->version,
            'plan' => $plan->code,
            'tier' => $tier,
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
            if ($conversation->status === Conversation::AWAITING_TOOLS) {
                throw AssistantException::toolResultsPending();
            }

            $this->guardCanReceiveMessage($conversation);

            if (! $conversation->claim(Conversation::AWAITING_USER)) {
                throw AssistantException::conversationBusy();
            }

            // Tela de onde veio a última pergunta: vai junto com a ideia/dúvida anotada para o Foxy Admin.
            $conversation->forceFill(['last_page' => $page !== null && trim($page) !== '' ? mb_substr(trim($page), 0, 200) : null])->save();

            $userMessage = ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $this->contextBlock($page, in_array('erros', $conversation->capabilities ?? AiPlan::CAPABILITIES, true) ? $error : null)],
                ['type' => 'text', 'text' => $text],
            ]];

            return $this->runTurn($conversation, [$userMessage], Conversation::AWAITING_USER, startsWithUserMessage: true);
        });
    }

    /**
     * Resultados das tools executadas pelo anfitrião.
     *
     * @param  list<array{id: string, output: mixed, is_error?: bool}>  $results
     */
    public function submitToolResults(Conversation $conversation, array $results): AssistantResult
    {
        return $this->asAgent($conversation, function () use ($conversation, $results) {
            if ($conversation->expires_at->isPast()) {
                throw AssistantException::conversationExpired();
            }

            if ($conversation->status === Conversation::AWAITING_USER) {
                throw AssistantException::noPendingTools();
            }

            $pending = $conversation->pending_tool_calls ?? ['calls' => []];
            // Pendências gravadas antes das mensagens sugeridas guardavam só 'escalate'.
            $effects = $pending['effects'] ?? ['escalate' => $pending['escalate'] ?? null, 'drafts' => []];
            $blocks = $this->toolResultBlocks($pending['calls'], $results);

            if (! $conversation->claim(Conversation::AWAITING_TOOLS)) {
                throw AssistantException::conversationBusy();
            }

            Audit::record('ai.tool_results_received', 'ok', $conversation, [
                'tools' => array_values(array_map(fn ($c) => $c['name'], array_filter($pending['calls'], fn ($c) => $c['host'] ?? false))),
                'errors' => count(array_filter($results, fn ($r) => (bool) ($r['is_error'] ?? false))),
            ]);

            return $this->runTurn($conversation, [['role' => 'user', 'content' => $blocks]], Conversation::AWAITING_TOOLS, effects: $effects);
        });
    }

    /**
     * Monta os tool_result na ordem dos pedidos: os do anfitrião com o que ele devolveu, os demais já resolvidos.
     */
    private function toolResultBlocks(array $calls, array $results): array
    {
        $byId = [];
        foreach ($results as $result) {
            $byId[$result['id']] = $result;
        }

        $hostIds = array_column(array_filter($calls, fn ($c) => $c['host'] ?? false), 'id');
        $receivedIds = array_keys($byId);
        sort($hostIds);
        sort($receivedIds);

        if ($hostIds !== $receivedIds || count($byId) !== count($results)) {
            throw AssistantException::toolResultsMismatch($hostIds);
        }

        return array_map(function (array $call) use ($byId) {
            if (! ($call['host'] ?? false)) {
                return $call['result'];
            }

            $output = $byId[$call['id']]['output'];
            $content = is_string($output) ? $output : json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (mb_strlen($content) > 30_000) {
                throw AssistantException::toolResultTooLarge($call['name']);
            }

            return ($byId[$call['id']]['is_error'] ?? false)
                ? ToolResults::error($call['id'], $content)
                : ToolResults::ok($call['id'], $content);
        }, $calls);
    }

    /**
     * Chama o modelo até ele responder em texto ou pedir tools do anfitrião. Tools internas e pedidos
     * inválidos são resolvidos aqui; nada é gravado no histórico antes de a rodada terminar com sucesso.
     */
    private function runTurn(Conversation $conversation, array $newMessages, string $statusOnFailure, bool $startsWithUserMessage = false, ?array $effects = null): AssistantResult
    {
        $usage = [];
        $effects ??= InternalTools::emptyEffects();
        $maxRounds = config('foxy.ai.max_tool_rounds_per_message');

        try {
            while (true) {
                $response = $this->send($conversation, [...$conversation->history(), ...$newMessages], $conversation->tool_rounds < $maxRounds);
                $usage[] = $record = UsageRecord::fromResponse($response, $conversation->getKey());
                $this->entitlements->recordUsage(self::METER, $conversation->user_ref, 0, $record->cost_usd);
                $this->account->spend($record->cost_usd);

                $newMessages[] = ['role' => 'assistant', 'content' => $this->assistantContent($response)];
                $calls = $response->stopReason === 'tool_use' ? $response->toolUses() : [];

                if ($calls === []) {
                    break;
                }

                $conversation->tool_rounds++;
                $pending = $this->classifyToolCalls($conversation, $calls, $effects);

                if (array_filter($pending, fn ($c) => $c['host'])) {
                    return $this->pauseForHostTools($conversation, $newMessages, $pending, $effects, $usage, $startsWithUserMessage);
                }

                $newMessages[] = ['role' => 'user', 'content' => array_column($pending, 'result')];
            }
        } catch (ProviderUnavailable $e) {
            $conversation->refresh()->release($statusOnFailure, $conversation->pending_tool_calls);

            throw $e instanceof \App\Ai\Providers\ProviderOutOfCredit ? AssistantException::providerOutOfCredit() : AssistantException::providerUnavailable();
        }

        $this->persist($conversation, $newMessages, $startsWithUserMessage, ['tool_rounds' => 0]);
        $conversation->release(Conversation::AWAITING_USER);

        $refused = $response->stopReason === 'refusal';
        $reply = $refused && $response->text() === '' ? self::REFUSAL_REPLY : $response->text();

        if ($effects['escalate']) {
            Audit::record('ai.escalated', 'ok', $conversation, ['reason' => $effects['escalate']['reason']]);
        }

        // Ideias e dúvidas vão para o Foxy Admin (de todos os produtos), além de voltarem ao sistema.
        foreach (['ideia' => 'feature_request', 'duvida' => 'knowledge_gap'] as $kind => $effect) {
            if ($summary = $effects[$effect]['summary'] ?? null) {
                AiInsight::create(['organization_id' => $conversation->organization_id, 'kind' => $kind, 'summary' => mb_substr($summary, 0, 500), 'page' => $conversation->last_page]);
                Audit::record($kind === 'ideia' ? 'ai.feature_requested' : 'ai.knowledge_gap', 'ok', $conversation);
            }
        }

        if ($effects['drafts'] !== []) {
            Audit::record('ai.drafts_suggested', 'ok', $conversation, ['count' => count($effects['drafts']), 'channels' => array_column($effects['drafts'], 'channel')]);
        }

        Audit::record('ai.message_answered', $refused ? 'refused' : 'ok', $conversation, [
            'model' => $response->model,
            'stop_reason' => $response->stopReason,
            'model_calls' => count($usage),
        ]);

        return new AssistantResult($conversation, AssistantResult::ANSWERED, $reply, $usage, escalate: $effects['escalate'], drafts: $effects['drafts'], featureRequest: $effects['feature_request'] ?? null, knowledgeGap: $effects['knowledge_gap'] ?? null);
    }

    /**
     * Separa os pedidos de tool: internos (resolvidos aqui), do anfitrião (válidos, vão para ele) e
     * recusados (fora da lista permitida ou com entrada inválida — o modelo recebe o erro).
     *
     * @return list<array{id: string, name: string, input: array, host: bool, result?: array}>
     */
    private function classifyToolCalls(Conversation $conversation, array $calls, array &$effects): array
    {
        $catalog = $conversation->hostProfile->toolCatalogs()->where('version', $conversation->tools_version)->first();
        $pending = [];

        foreach ($calls as $call) {
            $entry = $call + ['host' => false];

            if (InternalTools::isInternal($call['name'])) {
                [$entry['result'], $effect] = InternalTools::run($call);
                $effects = InternalTools::merge($effects, $effect);
            } elseif (! in_array($call['name'], $conversation->allowed_tools, true) || ! ($tool = $catalog?->find($call['name']))) {
                $entry['result'] = ToolResults::error($call['id'], 'Ferramenta não disponível nesta conversa.');
                Audit::record('ai.tool_rejected', 'rejected', $conversation, ['tool' => $call['name']]);
            } elseif ($errors = ToolSchema::inputErrors($tool['input_schema'], $call['input'])) {
                $entry['result'] = ToolResults::error($call['id'], 'Entrada inválida: '.implode(' ', $errors));
            } else {
                $entry['host'] = true;
            }

            $pending[] = $entry;
        }

        return $pending;
    }

    private function pauseForHostTools(Conversation $conversation, array $newMessages, array $pending, array $effects, array $usage, bool $startsWithUserMessage): AssistantResult
    {
        $this->persist($conversation, $newMessages, $startsWithUserMessage, ['tool_rounds' => $conversation->tool_rounds]);
        $conversation->release(Conversation::AWAITING_TOOLS, ['calls' => $pending, 'effects' => $effects]);

        $hostCalls = array_values(array_map(
            fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'input' => $c['input'] === [] ? new \stdClass : $c['input']],
            array_filter($pending, fn ($c) => $c['host']),
        ));

        Audit::record('ai.tool_requested', 'ok', $conversation, ['tools' => array_column($hostCalls, 'name')]);

        return new AssistantResult($conversation, AssistantResult::TOOL_REQUESTED, null, $usage, toolCalls: $hostCalls);
    }

    /**
     * Grava as mensagens novas; a mensagem do usuário conta no limite da conversa e no uso do mês.
     */
    private function persist(Conversation $conversation, array $newMessages, bool $startsWithUserMessage, array $attributes): void
    {
        $conversation->appendToHistory(...$newMessages);

        if ($startsWithUserMessage) {
            $attributes['user_messages'] = $conversation->user_messages + 1;
            $this->entitlements->recordUsage(self::METER, $conversation->user_ref, 1);
        }

        $conversation->forceFill($attributes);
    }


    private function guardCanReceiveMessage(Conversation $conversation): void
    {
        if ($conversation->expires_at->isPast()) {
            throw AssistantException::conversationExpired();
        }

        $max = $this->account->plan()?->maxUserMessages ?? config('foxy.ai.max_user_messages_per_conversation');
        if ($conversation->user_messages >= $max) {
            throw AssistantException::conversationFull($max);
        }

        $key = 'ai-user:'.$conversation->organization_id.':'.$conversation->user_ref;
        if (RateLimiter::tooManyAttempts($key, config('foxy.ai.user_messages_per_minute'))) {
            throw AssistantException::userRateLimited();
        }
        RateLimiter::hit($key, 60);

        if (! $this->account->plan()) {
            throw AssistantException::noAiPlan();
        }

        if (! $this->account->hasCredits()) {
            throw AssistantException::creditLimit();
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
