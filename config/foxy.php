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

];
