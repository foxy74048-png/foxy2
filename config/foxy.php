<?php

return [

    /*
    | Limites padrão por organização (cada organização pode ter os seus em organizations.limits).
    | null = sem limite.
    */
    'limits' => [
        'ai.messages.monthly' => env('FOXY_LIMIT_AI_MESSAGES_MONTHLY', 2000),
        'ai.messages.monthly_per_subject' => env('FOXY_LIMIT_AI_MESSAGES_PER_USER_MONTHLY', 200),
    ],

    /*
    | Requisições por minuto por chave de API.
    */
    'api_rate_limit_per_minute' => (int) env('FOXY_API_RATE_LIMIT', 120),

    'ai' => [
        'provider' => env('FOXY_AI_PROVIDER', 'anthropic'),
        'anthropic_api_key' => env('ANTHROPIC_API_KEY'),

        // Modelo e esforço padrão; cada conversa fica com os valores da abertura.
        'model' => env('FOXY_AI_MODEL', 'claude-opus-5-5'),
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
