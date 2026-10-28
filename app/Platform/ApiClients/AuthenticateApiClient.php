<?php

namespace App\Platform\ApiClients;

use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiClient
{
    public function __construct(private ContextStore $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();
        $client = $key !== '' ? ApiClient::findActiveByKey($key) : null;

        if (! $client || ! $client->organization?->isActive()) {
            return ApiError::response('unauthenticated', 'Chave de API ausente, inválida ou revogada.', 401);
        }

        $this->contexts->set(ExecutionContext::forApiClient($client->organization, $client->getKey()));
        $request->attributes->set('api_client', $client);

        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            $client->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $response = $next($request);
        $response->headers->set('X-Request-Id', $this->contexts->get()->correlationId);

        return $response;
    }

    public function terminate(): void
    {
        $this->contexts->forget();
    }
}
