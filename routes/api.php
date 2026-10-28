<?php

use App\Ai\Http\ConversationController;
use App\Ai\Http\HostProfileController;
use App\Platform\Context\ExecutionContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api.client', 'throttle:api-client'])->group(function () {
    // Permite ao sistema consumidor conferir se a chave está funcionando.
    Route::get('/me', function (Request $request) {
        $client = $request->attributes->get('api_client');

        return [
            'organization' => ['id' => $client->organization_id, 'name' => ExecutionContext::current()->organization->name],
            'api_client' => ['id' => $client->getKey(), 'name' => $client->name, 'scopes' => $client->scopes],
            'request_id' => ExecutionContext::current()->correlationId,
        ];
    });

    Route::middleware('api.scope:ai.host_profiles')->group(function () {
        Route::put('/host-profiles/{slug}', [HostProfileController::class, 'upsert']);
        Route::get('/host-profiles/{slug}', [HostProfileController::class, 'show']);
        Route::put('/host-profiles/{slug}/knowledge', [HostProfileController::class, 'putKnowledge']);
        Route::put('/host-profiles/{slug}/tools', [HostProfileController::class, 'putTools']);
    });

    Route::middleware('api.scope:ai.conversations')->group(function () {
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::post('/conversations/{id}/messages', [ConversationController::class, 'message']);
        Route::post('/conversations/{id}/tool-results', [ConversationController::class, 'toolResults']);
    });
});
