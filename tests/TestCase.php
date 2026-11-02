<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Plano só de teste, menor que os reais: sem consultas aos dados nem mensagens e só no modo rápido.
     * Serve para provar que a Foxy AI respeita capacidades e modos do plano.
     */
    protected function defineBasicAiPlan(): string
    {
        config(['foxy.ai.plans.ia-basico' => [
            'name' => 'IA básico (teste)', 'credits' => 1000, 'capabilities' => ['suporte', 'erros'],
            'tiers' => ['rapida'], 'default_tier' => 'rapida',
        ]]);

        return 'ia-basico';
    }
}
