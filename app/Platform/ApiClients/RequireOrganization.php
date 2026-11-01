<?php

namespace App\Platform\ApiClients;

use App\Platform\Context\ExecutionContext;
use App\Platform\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rotas de dados de cliente (conversas, uso...) exigem saber de qual cliente é a chamada.
 */
class RequireOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ExecutionContext::current()->organization) {
            return ApiError::response('organization_required', 'Informe o cliente no cabeçalho '.AuthenticateApiClient::ORGANIZATION_HEADER.'.', 400);
        }

        return $next($request);
    }
}
