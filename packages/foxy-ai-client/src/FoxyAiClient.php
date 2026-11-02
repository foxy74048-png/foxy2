<?php

namespace Foxy\AiClient;

/**
 * Cliente da Foxy AI para um sistema Foxy. Guarda a chave no servidor e faz a conversa inteira,
 * inclusive o ciclo "a IA pede uma consulta → o sistema executa → devolve".
 *
 *   $foxy = new FoxyAiClient(new HttpTransport(config('services.foxy_ai.url'), config('services.foxy_ai.key')));
 *   $clinic = $foxy->forOrganization($workspace->foxy_organization_id);
 *   $answer = $clinic->ask($conversationId, 'Quem mais me deve?', fn ($tool, $input) => $minhasConsultas->run($tool, $input));
 *   return response()->json($answer); // formato que a janela da raposa (foxy-assistant.js) entende
 */
class FoxyAiClient
{
    private const MAX_TOOL_ROUNDS = 6;

    /**
     * @param  array<string, string>  $toolLabels  nome da consulta → como mostrar à pessoa (ex.: financeiro_saldos → "Financeiro")
     */
    public function __construct(private Transport $transport, private ?string $organization = null, private array $toolLabels = []) {}

    public function forOrganization(string $organizationId): self
    {
        return new self($this->transport, $organizationId, $this->toolLabels);
    }

    public function withToolLabels(array $labels): self
    {
        return new self($this->transport, $this->organization, $labels + $this->toolLabels);
    }

    // ---------- Sistema (uma vez / a cada mudança) ----------

    public function publishProfile(string $slug, array $profile, string $knowledge, array $tools): array
    {
        return [
            $this->call('PUT', "/host-profiles/{$slug}", $profile, false),
            $this->call('PUT', "/host-profiles/{$slug}/knowledge", ['content' => $knowledge], false),
            $this->call('PUT', "/host-profiles/{$slug}/tools", ['tools' => $tools], false),
        ];
    }

    /**
     * Cria (ou atualiza, pelo seu identificador) o cliente na Foxy AI e devolve o id dele.
     */
    public function upsertOrganization(string $name, string $externalRef): array
    {
        return $this->call('POST', '/organizations', ['name' => $name, 'external_ref' => $externalRef], false);
    }

    public function setPlan(string $organizationId, string $plan, string $status = 'active'): array
    {
        return $this->call('PUT', "/organizations/{$organizationId}/ai-subscription", ['plan' => $plan, 'status' => $status], false);
    }

    public function addCredits(string $organizationId, int $credits, ?string $reference = null): array
    {
        return $this->call('POST', "/organizations/{$organizationId}/ai-credits", array_filter(['credits' => $credits, 'reference' => $reference]), false);
    }

    // ---------- Cliente (cada conversa) ----------

    /**
     * @param  array{host_profile: string, user_ref: string, allowed_tools?: list<string>, allowed_capabilities?: list<string>, tier?: string}  $payload
     */
    public function openConversation(array $payload): array
    {
        return $this->call('POST', '/conversations', $payload);
    }

    public function assistants(?string $userRef = null): array
    {
        return $this->call('GET', '/assistants'.($userRef ? '?user_ref='.rawurlencode($userRef) : ''));
    }

    /**
     * @param  string|null  $visual  estilo do desenho: facetas (padrão), linhas ou neon
     */
    public function chooseAssistant(?string $userRef, string $assistant, ?string $customName = null, ?string $visual = null): array
    {
        return $this->call('PUT', '/assistant-preferences', array_filter([
            'user_ref' => $userRef,
            'assistant' => $assistant,
            'custom_name' => $customName,
            'visual' => $visual,
        ]));
    }

    /**
     * Textos recentes da conversa (para a janela mostrar ao reabrir).
     */
    public function history(string $conversationId): array
    {
        return $this->call('GET', "/conversations/{$conversationId}/messages");
    }

    /**
     * Uso do mês de todos os clientes deste sistema (perguntas, créditos por modo, custo).
     */
    public function usageByClient(?string $month = null): array
    {
        return $this->call('GET', '/ai/usage'.($month ? "?month={$month}" : ''), [], false);
    }

    /**
     * Uso do mês deste cliente (créditos do plano, extras, usados, por modo); com $userRef, também o da pessoa.
     */
    public function usage(?string $month = null, ?string $userRef = null): array
    {
        $query = http_build_query(array_filter(['month' => $month, 'user_ref' => $userRef]));

        return $this->call('GET', '/usage'.($query !== '' ? "?{$query}" : ''));
    }

    /**
     * Envia a mensagem e resolve as consultas pedidas pela IA com $runTool (que deve respeitar as permissões
     * da pessoa). Devolve o formato da janela da raposa:
     * { reply, drafts, escalate, feature_request, knowledge_gap, tools_used, trace, usage, model, error }.
     *
     * @param  callable(string $tool, array $input): mixed  $runTool  lance exceção para devolver erro à IA
     * @param  array{message: string, code?: string, action?: string}|null  $error  erro mostrado na tela
     */
    public function ask(string $conversationId, string $text, callable $runTool, ?string $page = null, ?array $error = null): array
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost_usd' => 0.0];
        $trace = [];

        $response = $this->call('POST', "/conversations/{$conversationId}/messages", array_filter([
            'text' => $text,
            'page' => $page,
            'error' => $error,
        ]));

        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            if ($response['status'] >= 300) {
                return $this->answer(null, [], null, $trace, $usage, null, $response['body']['error'] ?? ['message' => 'Erro na Foxy AI.']);
            }

            $body = $response['body'];
            foreach (array_keys($usage) as $field) {
                $usage[$field] += $body['usage'][$field] ?? 0;
            }

            if (($body['status'] ?? null) === 'answered') {
                return $this->answer($body['reply'] ?? '', $body['drafts'] ?? [], $body['escalate'] ?? null, $trace, $usage, $body['usage']['model'] ?? null, null, $body['feature_request'] ?? null, $body['knowledge_gap'] ?? null);
            }

            $results = [];
            foreach ($body['tool_calls'] ?? [] as $call) {
                $input = (array) $call['input'];

                try {
                    $output = $runTool($call['name'], $input);
                    $results[] = ['id' => $call['id'], 'output' => $output];
                } catch (\Throwable $e) {
                    $output = 'Não foi possível consultar: '.$e->getMessage();
                    $results[] = ['id' => $call['id'], 'output' => $output, 'is_error' => true];
                }

                $trace[] = ['tool' => $call['name'], 'input' => $input, 'output' => $output];
            }

            $response = $this->call('POST', "/conversations/{$conversationId}/tool-results", ['results' => $results]);
        }

        return $this->answer(null, [], null, $trace, $usage, null, ['message' => 'Muitas rodadas de consultas.']);
    }

    private function answer(?string $reply, array $drafts, ?array $escalate, array $trace, array $usage, ?string $model, ?array $error = null, ?array $featureRequest = null, ?array $knowledgeGap = null): array
    {
        $used = array_values(array_unique(array_map(fn ($t) => $this->toolLabels[$t['tool']] ?? $t['tool'], $trace)));

        return [
            'reply' => $reply,
            'drafts' => $drafts,
            'escalate' => $escalate,
            'feature_request' => $featureRequest,
            'knowledge_gap' => $knowledgeGap,
            'tools_used' => $used,
            'trace' => $trace,
            'usage' => $usage,
            'model' => $model,
            'error' => $error,
        ];
    }

    private function call(string $method, string $uri, array $body = [], bool $asClient = true): array
    {
        return $this->transport->call($method, $uri, $body, $asClient ? $this->organization : null);
    }
}
