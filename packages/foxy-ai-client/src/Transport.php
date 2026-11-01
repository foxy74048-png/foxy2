<?php

namespace Foxy\AiClient;

/**
 * Como o cliente chega à API da Foxy AI. Em produção, HTTP; nos testes e no console, pode ser por dentro.
 */
interface Transport
{
    /**
     * @param  string|null  $organization  cliente da chamada (cabeçalho X-Foxy-Organization), para chaves do sistema
     * @return array{status: int, body: array}
     */
    public function call(string $method, string $uri, array $body = [], ?string $organization = null): array;
}
