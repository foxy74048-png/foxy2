<?php

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
});
