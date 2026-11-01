<?php

namespace App\Ai;

use App\Ai\Providers\AnthropicProvider;
use App\Ai\Providers\FakeProvider;
use App\Ai\Providers\ModelProvider;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModelProvider::class, function () {
            return match (config('foxy.ai.provider')) {
                'anthropic' => new AnthropicProvider(
                    apiKey: (string) config('foxy.ai.anthropic_api_key') ?: throw new LogicException('ANTHROPIC_API_KEY não configurada.'),
                    refusalFallbacks: config('foxy.ai.refusal_fallbacks') ?: null,
                    timeoutSeconds: config('foxy.ai.timeout_seconds'),
                    workspaceId: config('foxy.ai.anthropic_workspace_id') ?: null,
                ),
                'fake' => new FakeProvider,
                default => throw new LogicException('FOXY_AI_PROVIDER inválido.'),
            };
        });
    }
}
