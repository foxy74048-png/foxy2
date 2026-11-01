<?php

namespace App\Demo\Http;

use App\Ai\Assistants\FoxCatalog;
use App\Demo\DemoBusiness;
use App\Demo\DemoHost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Console de testes da equipe Foxy: conversa com a Foxy AI como o negócio de demonstração.
 */
class ConsoleController
{
    public function __construct(private DemoHost $host) {}

    public function index(): View
    {
        return view('console.index', [
            'ready' => DemoHost::isReady(),
            'models' => array_values(config('foxy.ai.tiers')),
            'defaultModel' => config('foxy.ai.tiers.rapida'),
            'roles' => array_keys(DemoBusiness::ROLES),
            'questions' => DemoBusiness::evaluationQuestions(),
            'provider' => config('foxy.ai.provider'),
            'foxes' => FoxCatalog::all(),
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'model' => ['required', Rule::in(array_values(config('foxy.ai.tiers')))],
            'assistant' => ['required', Rule::in(FoxCatalog::ids())],
            'assistant_name' => ['nullable', 'string', 'max:40'],
        ]);

        $fox = [$data['assistant'], trim((string) ($data['assistant_name'] ?? ''))];
        $chat = $request->session()->get('console.chat');
        $isNew = ! $chat || $chat['model'] !== $data['model'] || $chat['role'] !== $data['role'] || ($chat['fox'] ?? null) !== $fox;

        if ($isNew) {
            // Como um sistema real: grava a raposa escolhida pela pessoa e abre conversa nova com ela.
            $chosen = $this->host->chooseFox($this->userRef($request, $data['role']), $fox[0], $fox[1] ?: null);
            if ($chosen['status'] !== 200) {
                return response()->json(['error' => $chosen['body']['error'] ?? ['message' => 'Nome de raposa inválido.']], 422);
            }

            $opened = $this->host->openConversation($this->userRef($request, $data['role']), $data['role'], $data['model']);

            if ($opened['status'] !== 201) {
                return response()->json(['error' => $opened['body']['error'] ?? ['message' => 'Erro ao abrir conversa']], 422);
            }

            $chat = ['id' => $opened['body']['conversation_id'], 'model' => $data['model'], 'role' => $data['role'], 'fox' => $fox, 'assistant' => $opened['body']['assistant']];
            $request->session()->put('console.chat', $chat);
        }

        $answer = $this->host->ask($chat['id'], $data['question'], $data['page'] ?? null, $this->error($data));

        if (in_array($answer['error']['code'] ?? null, ['conversation_full', 'conversation_expired'], true)) {
            $request->session()->forget('console.chat');
        }

        return response()->json($answer + ['conversation_id' => $chat['id'], 'new_conversation' => $isNew, 'assistant' => $chat['assistant'] ?? null]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->session()->forget('console.chat');

        return response()->json(['ok' => true]);
    }

    /**
     * A mesma pergunta em conversas novas, uma por modelo, para comparar qualidade e custo.
     */
    public function compare(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'models' => ['required', 'array', 'min:1', 'max:3'],
            'models.*' => [Rule::in(array_values(config('foxy.ai.tiers')))],
        ]);

        $results = [];
        foreach (array_unique($data['models']) as $model) {
            $opened = $this->host->openConversation($this->userRef($request, $data['role']).'-comparar', $data['role'], $model);

            $results[] = ['model' => $model] + ($opened['status'] === 201
                ? $this->host->ask($opened['body']['conversation_id'], $data['question'], $data['page'] ?? null, $this->error($data))
                : ['reply' => null, 'escalate' => null, 'trace' => [], 'usage' => null, 'error' => $opened['body']['error'] ?? null]);
        }

        return response()->json(['results' => $results]);
    }

    private function validated(Request $request, array $extra): array
    {
        return $request->validate([
            'question' => ['required', 'string', 'max:4000'],
            'role' => ['required', Rule::in(array_keys(DemoBusiness::ROLES))],
            'page' => ['nullable', 'string', 'max:200'],
            'error_message' => ['nullable', 'string', 'max:500'],
            'error_code' => ['nullable', 'string', 'max:60'],
        ] + $extra);
    }

    private function error(array $data): ?array
    {
        return empty($data['error_message']) ? null : array_filter(['message' => $data['error_message'], 'code' => $data['error_code'] ?? null]);
    }

    private function userRef(Request $request, string $role): string
    {
        return 'console-'.$request->user()->getKey().'-'.$role;
    }
}
