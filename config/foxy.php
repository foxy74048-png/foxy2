<?php

$plan = fn (int $credits, array $capabilities, array $tiers) => [
    'name' => 'IA '.number_format($credits, 0, ',', '.'),
    'credits' => $credits,
    'capabilities' => $capabilities,
    'tiers' => $tiers,
    'default_tier' => 'rapida',
];

$full = ['suporte', 'erros', 'dados', 'escrita'];
$allTiers = ['rapida', 'equilibrada', 'avancada'];

return [

    /*
    | Limites genéricos por organização (organizations.limits ou aqui). null = sem limite.
    | A IA não usa mais limite de mensagens: usa créditos do plano (abaixo).
    */
    'limits' => [],

    /*
    | Requisições por minuto por chave de API.
    */
    'api_rate_limit_per_minute' => (int) env('FOXY_API_RATE_LIMIT', 120),

    'ai' => [
        'provider' => env('FOXY_AI_PROVIDER', 'anthropic'),
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),
        // Só para chaves sem workspace fixo (ex.: wrkspc_...). Chave criada dentro de um workspace não precisa.
        'anthropic_workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),

        /*
        | Faixas de modelo (docs/07, seção 2.2). Os planos citam faixas, nunca modelos: trocar o modelo
        | de uma faixa vale para todos os planos, sem migração.
        */
        'tiers' => [
            'rapida' => env('FOXY_AI_MODEL_RAPIDA', 'claude-haiku-5-5'),
            'equilibrada' => env('FOXY_AI_MODEL_EQUILIBRADA', 'claude-sonnet-5-5'),
            'avancada' => env('FOXY_AI_MODEL_AVANCADA', 'claude-opus-5-5'),
        ],

        /*
        | Planos (docs/07, seção 2.3; tamanhos revistos em 2026-11-02). Créditos por mês; 1 crédito = US$ 0,001 de custo
        | de IA ≈ 1 pergunta no modo rápido. Todos os planos têm todas as capacidades e todos os modos: o modo mais
        | caro só gasta os créditos mais depressa (a pessoa escolhe). Créditos extras: pacotes de 1.000.
        */
        'credit_usd' => 0.001,
        'extra_credits_step' => 1000,
        'plans' => [
            'ia-250' => $plan(250, $full, $allTiers),
            'ia-1000' => $plan(1000, $full, $allTiers),
            'ia-1500' => $plan(1500, $full, $allTiers),
            'ia-2000' => $plan(2000, $full, $allTiers),
            'ia-5000' => $plan(5000, $full, $allTiers),
            'ia-10000' => $plan(10000, $full, $allTiers),
            'ia-25000' => $plan(25000, $full, $allTiers),
            'ia-50000' => $plan(50000, $full, $allTiers),
        ],
        // Plano usado quando o cliente ainda não tem assinatura (null = sem IA até o sistema definir o plano).
        'default_plan' => env('FOXY_AI_DEFAULT_PLAN'),

        'effort' => env('FOXY_AI_EFFORT', 'low'),
        'max_tokens' => (int) env('FOXY_AI_MAX_TOKENS', 8000),
        'timeout_seconds' => (float) env('FOXY_AI_TIMEOUT', 90),

        // Se o modelo recusar por política, a Anthropic tenta o modelo reserva padrão dela.
        'refusal_fallbacks' => env('FOXY_AI_REFUSAL_FALLBACKS', 'default'),

        'max_user_messages_per_conversation' => (int) env('FOXY_AI_MAX_USER_MESSAGES', 20),
        'max_tool_rounds_per_message' => 4,
        'user_messages_per_minute' => (int) env('FOXY_AI_USER_MESSAGES_PER_MINUTE', 10),
        'conversation_retention_days' => 30,

        // Dólares por milhão de tokens (consultado em 2026-10-28). Custo gravado é estimativa.
        'prices' => [
            'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
            'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.20, 'cache_write' => 2.50],
            'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
            'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
            'claude-opus-4-8' => ['input' => 5.00, 'output' => 25.00, 'cache_read' => 0.50, 'cache_write' => 6.25],
        ],
    ],

];
