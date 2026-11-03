<?php

// Histórico recente: planos maiores guardam a conversa por mais tempo e aceitam conversas mais longas.
// Passou do prazo, a conversa é apagada (foxy:ai:purge-conversations); passou do limite, começa-se outra.
$plan = fn (int $credits, array $capabilities, array $tiers, int $retentionDays, int $maxUserMessages) => [
    'name' => 'IA '.number_format($credits, 0, ',', '.'),
    'credits' => $credits,
    'capabilities' => $capabilities,
    'tiers' => $tiers,
    'default_tier' => 'rapida',
    'retention_days' => $retentionDays,
    'max_user_messages' => $maxUserMessages,
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
    | Endereços (docs/08): "/" mostra a vitrine da marca em foxy.net.br e leva ao Foxy Admin em admin.foxy.net.br.
    */
    'hosts' => [
        'vitrine' => array_filter(explode(',', (string) env('FOXY_VITRINE_HOSTS', 'foxy.net.br,www.foxy.net.br'))),
        'admin' => env('FOXY_ADMIN_HOST', 'admin.foxy.net.br'),
    ],

    // Links da vitrine para os produtos.
    'products' => [
        'studio_url' => env('FOXY_STUDIO_URL', 'https://studio.foxy.net.br'),
    ],

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
        | caro só gasta os créditos mais depressa (a pessoa escolhe). Créditos extras: múltiplos de 250.
        */
        'credit_usd' => 0.001,
        // Pacotes extras em múltiplos de 250 (ex.: 250, 1.000, 3.000), somados à franquia do mês.
        'extra_credits_step' => 250,
        'plans' => [
            'ia-250' => $plan(250, $full, $allTiers, 7, 10),
            'ia-1000' => $plan(1000, $full, $allTiers, 15, 20),
            'ia-1500' => $plan(1500, $full, $allTiers, 15, 20),
            'ia-2000' => $plan(2000, $full, $allTiers, 15, 20),
            'ia-5000' => $plan(5000, $full, $allTiers, 30, 30),
            'ia-10000' => $plan(10000, $full, $allTiers, 30, 30),
            'ia-25000' => $plan(25000, $full, $allTiers, 30, 30),
            'ia-50000' => $plan(50000, $full, $allTiers, 30, 30),
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
