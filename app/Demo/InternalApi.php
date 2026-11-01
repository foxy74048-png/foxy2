<?php

namespace App\Demo;

use App\Platform\Context\ContextStore;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;

/**
 * Chama a API pública da Foxy AI por dentro da aplicação (sem rede), passando pelos mesmos
 * middlewares de um sistema externo: chave, escopo, limite e formato de erro.
 */
class InternalApi
{
    /**
     * @param  string|null  $organization  cliente da chamada (cabeçalho X-Foxy-Organization), para chaves do sistema
     */
    public function __construct(private string $key, private ?string $organization = null) {}

    /**
     * @return array{status: int, body: array}
     */
    public function call(string $method, string $uri, array $body = []): array
    {
        $app = app();
        $original = $app->bound('request') ? $app->make('request') : null;
        $contexts = $app->make(ContextStore::class);

        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];

        if ($this->organization) {
            $server['HTTP_X_FOXY_ORGANIZATION'] = $this->organization;
        }

        $request = Request::create('/api/v1'.$uri, $method, server: $server, content: $body === [] ? null : json_encode($body));

        try {
            $response = $app->make(Kernel::class)->handle($request);
        } finally {
            $contexts->forget();

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
