<?php

use App\Ai\Http\AiBillingController;
use App\Ai\Http\AssistantController;
use App\Ai\Http\ConversationController;
use App\Ai\Http\HostProfileController;
use App\Ai\Http\UsageController;
use App\Platform\Context\ExecutionContext;
use App\Platform\Http\OrganizationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.client', 'throttle:api-client'])->group(function () {
    // Permite ao sistema conferir se a chave está funcionando (e com qual cliente, se houver).
    Route::get('/me', function (Request $request) {
        $client = $request->attributes->get('api_client');
        $context = ExecutionContext::current();

        return [
            'integration' => ['id' => $context->integration->getKey(), 'name' => $context->integration->name],
            'organization' => $context->organization ? ['id' => $context->organization->getKey(), 'name' => $context->organization->name] : null,
            'api_client' => ['id' => $client->getKey(), 'name' => $client->name, 'scopes' => $client->scopes, 'pinned_to_organization' => (bool) $client->organization_id],
            'request_id' => $context->correlationId,
        ];
    });

    // Nível do sistema: dados comuns a todos os clientes da integração.
    Route::middleware('api.scope:ai.host_profiles')->group(function () {
        Route::put('/host-profiles/{slug}', [HostProfileController::class, 'upsert']);
        Route::get('/host-profiles/{slug}', [HostProfileController::class, 'show']);
        Route::put('/host-profiles/{slug}/knowledge', [HostProfileController::class, 'putKnowledge']);
        Route::put('/host-profiles/{slug}/tools', [HostProfileController::class, 'putTools']);
    });

    Route::middleware('api.scope:organizations')->group(function () {
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/organizations/{id}', [OrganizationController::class, 'show']);
        Route::patch('/organizations/{id}', [OrganizationController::class, 'update']);

        // Plano de IA e créditos extras de cada cliente (quem cobra o cliente é o sistema).
        Route::get('/ai/plans', [AiBillingController::class, 'plans']);
        Route::get('/ai/usage', [AiBillingController::class, 'usageByClient']);
        Route::get('/organizations/{id}/ai-subscription', [AiBillingController::class, 'showForClient']);
        Route::put('/organizations/{id}/ai-subscription', [AiBillingController::class, 'putSubscription']);
        Route::post('/organizations/{id}/ai-credits', [AiBillingController::class, 'addCredits']);
    });

    // Nível do cliente: exige saber de qual cliente é a chamada (chave presa ou X-Foxy-Organization).
    Route::middleware(['api.organization', 'api.scope:ai.conversations'])->group(function () {
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'message']);
        Route::get('/conversations/{id}/messages', [ConversationController::class, 'history']);
        Route::post('/conversations/{id}/tool-results', [ConversationController::class, 'toolResults']);
        Route::delete('/conversations', [ConversationController::class, 'destroyForUser']);
        Route::get('/usage', [UsageController::class, 'show']);

        // As raposas: catálogo e escolha (da conta ou de cada usuário).
        Route::get('/assistants', [AssistantController::class, 'index']);
        Route::put('/assistant-preferences', [AssistantController::class, 'putPreference']);
        Route::delete('/assistant-preferences', [AssistantController::class, 'deletePreference']);
    });
});
