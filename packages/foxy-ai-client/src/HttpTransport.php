<?php

namespace Foxy\AiClient;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class HttpTransport implements Transport
{
    /**
     * @param  string  $baseUrl  ex.: https://ai.foxy.net.br/api/v1
     * @param  string  $key  chave do sistema (fxy_...), guardada só no servidor
     */
    public function __construct(private string $baseUrl, private string $key, private float $timeoutSeconds = 100) {}

    public function call(string $method, string $uri, array $body = [], ?string $organization = null): array
    {
        $request = Http::withToken($this->key)->acceptJson()->asJson()->timeout($this->timeoutSeconds);

        if ($organization) {
            $request = $request->withHeaders(['X-Foxy-Organization' => $organization]);
        }

        try {
            $response = $request->send($method, rtrim($this->baseUrl, '/').$uri, $body === [] ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            return ['status' => 503, 'body' => ['error' => ['code' => 'ai_unreachable', 'message' => 'Não foi possível falar com a Foxy AI agora.']]];
        }

        return ['status' => $response->status(), 'body' => $response->json() ?? []];
    }
}
