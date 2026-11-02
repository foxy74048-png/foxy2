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
 * Console de testes da equipe Foxy: a clínica de demonstração com a janela da raposa de verdade
 * (public/core/foxy-assistant.js). Este controller faz o papel do "servidor do sistema": guarda a
 * chave, abre a conversa com as permissões do perfil escolhido e executa as consultas.
 */
class ConsoleController
{
    public function __construct(private DemoHost $host) {}

    public function index(Request $request): View
    {
        $ready = DemoHost::isReady();
        $current = $ready ? ($this->host->assistants($this->userRef($request))['body']['current'] ?? null) : null;

        return view('console.index', [
            'ready' => $ready,
            'models' => array_values(config('foxy.ai.tiers')),
            'defaultModel' => config('foxy.ai.tiers.rapida'),
            'roles' => array_keys(DemoBusiness::ROLES),
            'questions' => DemoBusiness::evaluationQuestions(),
            'provider' => config('foxy.ai.provider'),
            'foxes' => FoxCatalog::all(),
            'visuals' => FoxCatalog::visuals(),
            'current' => $current ?? FoxCatalog::get(FoxCatalog::DEFAULT),
        ]);
    }

    /**
     * Endpoint da janela da raposa: { text, page, error, model, role } → resposta no formato do widget.
     */
    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4000'],
            'page' => ['nullable', 'string', 'max:200'],
            'error' => ['nullable', 'array'],
            'error.message' => ['required_with:error', 'string', 'max:500'],
            'error.code' => ['nullable', 'string', 'max:60'],
            'model' => ['required', Rule::in(array_values(config('foxy.ai.tiers')))],
            'role' => ['required', Rule::in(array_keys(DemoBusiness::ROLES))],
        ]);

        $chat = $request->session()->get('console.chat');
        $isNew = ! $chat || $chat['model'] !== $data['model'] || $chat['role'] !== $data['role'];

        if ($isNew) {
            $opened = $this->host->openConversation($this->userRef($request), $data['role'], $data['model']);

            if ($opened['status'] !== 201) {
                return response()->json(['error' => $opened['body']['error'] ?? ['message' => 'Erro ao abrir conversa.']], 422);
            }

            $chat = ['id' => $opened['body']['conversation_id'], 'model' => $data['model'], 'role' => $data['role'], 'assistant' => $opened['body']['assistant']];
            $request->session()->put('console.chat', $chat);
        }

        $answer = $this->host->ask($chat['id'], $data['text'], $data['page'] ?? null, $data['error'] ?? null);

        if (in_array($answer['error']['code'] ?? null, ['conversation_full', 'conversation_expired'], true)) {
            $request->session()->forget('console.chat');
        }

        return response()->json($answer + ['assistant' => $chat['assistant'], 'conversation_id' => $chat['id'], 'new_conversation' => $isNew]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->session()->forget('console.chat');

        return response()->json(['assistant' => $this->host->assistants($this->userRef($request))['body']['current'] ?? null]);
    }

    /**
     * "Escolha a sua raposa": grava a escolha da pessoa (como um sistema real faria) e começa conversa nova.
     */
    public function chooseFox(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assistant' => ['required', Rule::in(FoxCatalog::ids())],
            'custom_name' => ['nullable', 'string', 'max:40'],
            'visual' => ['nullable', Rule::in(array_keys(FoxCatalog::visuals()))],
        ]);

        $chosen = $this->host->chooseFox($this->userRef($request), $data['assistant'], trim((string) ($data['custom_name'] ?? '')) ?: null, $data['visual'] ?? null);

        if ($chosen['status'] !== 200) {
            return response()->json(['error' => $chosen['body']['error'] ?? ['message' => 'Não foi possível escolher a raposa.']], 422);
        }

        $request->session()->forget('console.chat');

        return response()->json(['assistant' => $chosen['body']['current']]);
    }

    /**
     * A mesma pergunta em conversas novas, uma por modelo, para comparar qualidade e custo.
     */
    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:4000'],
            'role' => ['required', Rule::in(array_keys(DemoBusiness::ROLES))],
            'page' => ['nullable', 'string', 'max:200'],
            'models' => ['required', 'array', 'min:1', 'max:3'],
            'models.*' => [Rule::in(array_values(config('foxy.ai.tiers')))],
        ]);

        $results = [];
        foreach (array_unique($data['models']) as $model) {
            $opened = $this->host->openConversation($this->userRef($request), $data['role'], $model);

            $results[] = ['model' => $model] + ($opened['status'] === 201
                ? $this->host->ask($opened['body']['conversation_id'], $data['question'], $data['page'] ?? null)
                : ['reply' => null, 'drafts' => [], 'escalate' => null, 'trace' => [], 'usage' => null, 'error' => $opened['body']['error'] ?? null]);
        }

        return response()->json(['results' => $results]);
    }

    private function userRef(Request $request): string
    {
        return 'console-'.$request->user()->getKey();
    }
}
