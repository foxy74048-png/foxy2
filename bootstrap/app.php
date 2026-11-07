<?php

use App\Ai\Assistant\AssistantException;
use App\Commerce\Http\Middleware\EnforceSubscription;
use App\Commerce\Http\Middleware\RequirePermission;
use App\Commerce\Http\Middleware\ResolveStore;
use App\Platform\ApiClients\AuthenticateApiClient;
use App\Platform\ApiClients\RequireOrganization;
use App\Platform\ApiClients\RequireScope;
use App\Platform\Http\ApiError;
use App\Platform\Identity\RequireStaff;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Platform/Console', __DIR__.'/../app/Ai/Console', __DIR__.'/../app/Demo/Console', __DIR__.'/../app/Commerce/Console'])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.client' => AuthenticateApiClient::class,
            'api.scope' => RequireScope::class,
            'api.organization' => RequireOrganization::class,
            'staff' => RequireStaff::class,
            'commerce.store' => ResolveStore::class,
            'commerce.can' => RequirePermission::class,
            'commerce.subscription' => EnforceSubscription::class,
        ]);

        // Cada produto tem o seu login: no endereço do Commerce, o do Commerce; no resto, o da equipe Foxy.
        $isCommerce = fn (Request $request) => strtolower($request->getHost()) === config('foxy.hosts.commerce');
        $middleware->redirectGuestsTo(fn (Request $request) => $isCommerce($request) ? route('commerce.login') : route('login'));
        $middleware->redirectUsersTo(fn (Request $request) => $isCommerce($request) ? route('commerce.dashboard') : '/console');

        // A loja (contexto) precisa existir antes de carregar o model da rota: o escopo da loja filtra a busca.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveStore::class);

        // O Stripe não manda token de formulário; o webhook confere a assinatura dele.
        $middleware->validateCsrfTokens(except: ['stripe/webhook']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Situações esperadas (limite, conversa expirada...) viram resposta da API, não erro no log.
        $exceptions->dontReport(AssistantException::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Na API, todo erro sai no formato único de docs/05-arquitetura.md (seção 4).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof AssistantException) {
                return ApiError::response($e->errorCode, $e->getMessage(), $e->status);
            }

            if ($e instanceof ModelNotFoundException) {
                return ApiError::response('not_found', 'Não encontrado.', 404);
            }

            if ($e instanceof ValidationException) {
                return ApiError::response('validation_failed', 'Dados inválidos.', 422, $e->errors());
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    404 => 'not_found',
                    405 => 'method_not_allowed',
                    429 => 'rate_limited',
                    default => 'http_'.$status,
                };

                return ApiError::response($code, $status === 404 ? 'Não encontrado.' : ($e->getMessage() ?: 'Erro na requisição.'), $status);
            }

            if (config('app.debug')) {
                return null;
            }

            return ApiError::response('internal_error', 'Erro interno. Informe o request_id ao suporte.', 500);
        });
    })->create();
