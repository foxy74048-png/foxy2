<?php

namespace App\Platform\Entitlements;

/**
 * O que a organização do contexto pode usar. Hoje: só uso medido por mês (N1);
 * planos e features entram aqui sem mudar quem chama — ver docs/04-recorte-platform.md.
 */
interface Entitlements
{
    public function hasFeature(string $feature): bool;

    /**
     * Ainda há saldo no mês para a organização e, se informado, para o sujeito (ex.: user_ref)?
     */
    public function canUse(string $meter, ?string $subjectRef = null): bool;

    public function recordUsage(string $meter, ?string $subjectRef = null, int $quantity = 1, float $costUsd = 0): void;

    /**
     * @return array{quantity: int, cost_usd: float}
     */
    public function usage(string $meter, ?string $subjectRef = null, ?string $period = null): array;
}
