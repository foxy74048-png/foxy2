<?php

namespace App\Platform\ApiClients;

use App\Platform\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $client = $request->attributes->get('api_client');

        if (! $client instanceof ApiClient || ! $client->hasScope($scope)) {
            return ApiError::response('forbidden', "Esta chave não tem o escopo {$scope}.", 403);
        }

        return $next($request);
    }
}
