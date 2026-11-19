<?php

namespace App\Ai\Tasks;

use App\Ai\Assistant\AssistantException;
use App\Ai\Plans\AiAccount;
use App\Ai\Providers\ModelProvider;
use App\Ai\Providers\ModelRequest;
use App\Ai\Providers\ProviderOutOfCredit;
use App\Ai\Providers\ProviderUnavailable;
use App\Ai\Usage\UsageRecord;
use App\Platform\Audit\Audit;
use App\Platform\Context\ExecutionContext;
use App\Platform\Entitlements\Entitlements;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Roda uma tarefa avulsa do catálogo (docs/12 §14) para a organização do contexto: confere plano, crédito e
 * ritmo, valida a entrada, chama o modelo rápido com a resposta presa ao esquema e devolve o JSON. O gasto
 * entra no mesmo crédito da conversa. O registro guarda o uso, não o conteúdo.
 */
class TaskRunner
{
    public const METER = 'ai.tasks';

    /** Tarefas por minuto por organização (evita laço acidental de um sistema). */
    public const PER_MINUTE = 30;

    public function __construct(
        private ModelProvider $provider,
        private Entitlements $entitlements,
        private AiAccount $account,
    ) {}

    /** @return array{output: array, usage: array} */
    public function run(string $name, array $input): array
    {
        $task = TaskCatalog::get($name) ?? throw new TaskException('unknown_task', 'Tarefa desconhecida.', 404);

        $validator = Validator::make($input, $task['input']);
        if ($validator->fails()) {
            throw new TaskException('invalid_input', 'Entrada inválida para a tarefa: '.$validator->errors()->first(), 422);
        }

        $organization = ExecutionContext::current()->organization;
        $key = 'ai-task:'.$organization->getKey();
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw AssistantException::userRateLimited();
        }
        RateLimiter::hit($key, 60);
        if (! $this->account->plan()) {
            throw AssistantException::noAiPlan();
        }
        if (! $this->account->hasCredits()) {
            throw AssistantException::creditLimit();
        }

        try {
            $response = $this->provider->send(new ModelRequest(
                model: config('foxy.ai.tiers.rapida'),
                effort: 'low',
                maxTokens: $task['max_tokens'],
                // Instrução fixa primeiro (cache igual para todos); os dados vão na mensagem.
                system: $task['instructions'],
                tools: [],
                messages: [['role' => 'user', 'content' => json_encode($validator->validated(), JSON_UNESCAPED_UNICODE)]],
                allowTools: false,
                outputSchema: $task['schema'],
            ));
        } catch (ProviderOutOfCredit) {
            throw AssistantException::providerOutOfCredit();
        } catch (ProviderUnavailable) {
            throw AssistantException::providerUnavailable();
        }

        $record = UsageRecord::fromResponse($response, null);
        $this->entitlements->recordUsage(self::METER, 'task:'.$name, 1, $record->cost_usd);
        $this->account->spend($record->cost_usd);

        $output = json_decode($response->text(), true);
        $ok = $response->stopReason !== 'refusal' && is_array($output);
        Audit::record('ai.task_run', $ok ? 'ok' : 'failed', null, ['task' => $name, 'model' => $response->model, 'stop_reason' => $response->stopReason]);
        if (! $ok) {
            throw new TaskException('task_failed', 'A raposa não conseguiu fazer essa tarefa agora.', 502);
        }

        return ['output' => $output, 'usage' => [
            'model' => $response->model, 'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
            'cost_usd' => $record->cost_usd, 'credits_left' => $this->account->availableCredits(),
        ]];
    }
}
