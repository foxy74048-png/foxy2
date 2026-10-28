<?php

namespace App\Ai\Providers;

/**
 * Único ponto que fala com um provedor de modelo. Trocar de provedor = nova implementação.
 */
interface ModelProvider
{
    /**
     * @throws ProviderUnavailable quando o provedor falha depois das novas tentativas
     */
    public function send(ModelRequest $request): ModelResponse;
}
