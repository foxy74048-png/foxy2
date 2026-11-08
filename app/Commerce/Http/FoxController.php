<?php

namespace App\Commerce\Http;

use App\Commerce\Assistant\CommerceFox;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Endpoints da janela da raposa no app do lojista (contrato em public/core/foxy-assistant.js).
 */
class FoxController
{
    public function __construct(private CommerceFox $fox, private ContextStore $contexts) {}

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'page' => ['nullable', 'string', 'max:200'],
            'error' => ['nullable', 'array:message,code,action'],
            'error.message' => ['required_with:error', 'string', 'max:500'],
            'error.code' => ['nullable', 'string', 'max:60'],
            'error.action' => ['nullable', 'string', 'max:200'],
        ]);

        return $this->guard(function () use ($request, $data) {
            $context = ExecutionContext::current();

            // A IA age em nome da pessoa (auditoria com on_behalf_of); as consultas respeitam o papel dela.
            return $this->contexts->run($context->asAiAgentFor((string) $request->user()->getKey()), fn () => $this->fox->ask(
                $context->organization, $request->user(), $request->attributes->get('commerce.membership'),
                $data['text'], $data['page'] ?? null, $data['error'] ?? null,
            ));
        });
    }

    public function reset(Request $request): JsonResponse
    {
        return $this->guard(function () use ($request) {
            $this->fox->forget();
            $conversation = $this->fox->open(ExecutionContext::current()->organization, $request->user(), $request->attributes->get('commerce.membership'));

            return ['assistant' => $conversation['assistant']];
        });
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assistant' => ['required', 'string', 'max:30'],
            'visual' => ['nullable', 'string', 'max:30'],
            'custom_name' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->guard(fn () => ['assistant' => $this->fox->choose(
            ExecutionContext::current()->organization, $request->user(), $data['assistant'], $data['visual'] ?? null, isset($data['custom_name']) ? trim($data['custom_name']) ?: null : null,
        )]);
    }

    private function guard(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => ['code' => 'unavailable', 'message' => 'A raposa está indisponível agora. Tente de novo em instantes.']]);
        }
    }
}
