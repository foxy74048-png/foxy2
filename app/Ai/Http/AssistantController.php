<?php

namespace App\Ai\Http;

use App\Ai\Assistants\AssistantPreference;
use App\Ai\Assistants\FoxCatalog;
use App\Platform\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * As raposas: catálogo, a escolhida (da conta ou do usuário) e o nome que a pessoa deu.
 * Vale para conversas novas; conversas abertas mantêm a raposa com que começaram.
 */
class AssistantController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['user_ref' => ['nullable', 'string', 'max:120']]);

        return response()->json([
            'data' => FoxCatalog::all(),
            'visuals' => FoxCatalog::visuals(),
            'current' => AssistantPreference::resolveFor($data['user_ref'] ?? null),
        ]);
    }

    /**
     * Sem user_ref = padrão da conta; com user_ref = escolha daquela pessoa.
     */
    public function putPreference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ref' => ['nullable', 'string', 'max:120'],
            'assistant' => ['required', Rule::in(FoxCatalog::ids())],
            'custom_name' => ['nullable', 'string', 'max:40', 'regex:/^[\pL\pN][\pL\pN \-\'.]*$/u'],
            'visual' => ['nullable', Rule::in(array_keys(FoxCatalog::visuals()))],
        ]);

        $userRef = $data['user_ref'] ?? '';

        AssistantPreference::updateOrCreate(
            ['user_ref' => $userRef],
            ['assistant' => $data['assistant'], 'custom_name' => isset($data['custom_name']) ? trim($data['custom_name']) : null, 'visual' => $data['visual'] ?? null],
        );

        Audit::record('ai.assistant_chosen', 'ok', null, [
            'scope' => $userRef === '' ? 'conta' : 'usuario',
            'assistant' => $data['assistant'],
            'visual' => $data['visual'] ?? FoxCatalog::DEFAULT_VISUAL,
            'renamed' => ! empty($data['custom_name']),
        ]);

        return response()->json(['current' => AssistantPreference::resolveFor($userRef === '' ? null : $userRef)]);
    }

    public function deletePreference(Request $request): JsonResponse
    {
        $data = $request->validate(['user_ref' => ['required', 'string', 'max:120']]);

        AssistantPreference::where('user_ref', $data['user_ref'])->delete();

        return response()->json(['current' => AssistantPreference::resolveFor($data['user_ref'])]);
    }
}
