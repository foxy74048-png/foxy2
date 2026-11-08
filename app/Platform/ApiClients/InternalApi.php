<?php

namespace App\Platform\ApiClients;

use App\Platform\Context\ContextStore;
use Foxy\AiClient\Transport;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Chama a API pública da Foxy AI por dentro da aplicação (sem rede), passando pelos mesmos
 * middlewares de um sistema externo: chave, escopo, limite e formato de erro.
 */
class InternalApi implements Transport
{
    /**
     * @param  string|null  $organization  cliente da chamada (cabeçalho X-Foxy-Organization), para chaves do sistema
     */
    public function __construct(private string $key, private ?string $organization = null) {}

    /**
     * @return array{status: int, body: array}
     */
    public function call(string $method, string $uri, array $body = [], ?string $organization = null): array
    {
        $organization ??= $this->organization;
        $app = app();
        $original = $app->bound('request') ? $app->make('request') : null;
        $contexts = $app->make(ContextStore::class);
        // Quem chama pode estar dentro de um contexto (ex.: a loja do Commerce): ele volta igual depois da chamada.
        $previous = $contexts->has() ? $contexts->get() : null;

        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($organization) {
            $server['HTTP_X_FOXY_ORGANIZATION'] = $organization;
        }

        $request = Request::create(rtrim((string) config('app.url'), '/').'/api/v1'.$uri, $method, server: $server, content: $body === [] ? null : json_encode($body));

        try {
            $response = $app->make(Kernel::class)->handle($request);
        } finally {
            $previous ? $contexts->set($previous) : $contexts->forget();

            if ($original) {
                $app->instance('request', $original);
                Facade::clearResolvedInstance('request');
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    }
}
