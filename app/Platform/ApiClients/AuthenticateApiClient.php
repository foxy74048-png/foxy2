<?php

namespace App\Platform\ApiClients;

use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Http\ApiError;
use App\Platform\Organizations\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a chave, descobre a integração e, se houver, o cliente (organização) da chamada:
 * chave presa a um cliente → esse cliente; chave do sistema → cabeçalho X-Foxy-Organization.
 */
class AuthenticateApiClient
{
    public const ORGANIZATION_HEADER = 'X-Foxy-Organization';

    public function __construct(private ContextStore $contexts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->bearerToken();
        $client = $key !== '' ? ApiClient::findActiveByKey($key) : null;

        if (! $client || ! $client->integration?->isActive()) {
            return ApiError::response('unauthenticated', 'Chave de API ausente, inválida ou revogada.', 401);
        }

        $context = ExecutionContext::forApiClient($client->integration, $client->getKey());
        $this->contexts->set($context);
        $request->attributes->set('api_client', $client);

        $requested = $request->header(self::ORGANIZATION_HEADER);

        if ($client->organization_id) {
            if ($requested && $requested !== $client->organization_id) {
                return ApiError::response('forbidden', 'Esta chave é de outro cliente.', 403);
            }

            $organization = $client->organization;
        } elseif ($requested) {
            $organization = Organization::where('integration_id', $client->integration_id)->find($requested);

            if (! $organization) {
                return ApiError::response('organization_not_found', 'Cliente (organização) não encontrado neste sistema.', 404);
            }
        }

        if (isset($organization)) {
            if (! $organization->isActive()) {
                return ApiError::response('organization_suspended', 'O acesso deste cliente à IA está suspenso.', 403);
            }

            $this->contexts->set($context->withOrganization($organization));
        }

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
