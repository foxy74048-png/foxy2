<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Cobrança da Foxy (assinaturas dos produtos). Webhook: https://ecommerce.foxy.net.br/stripe/webhook
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Mercado Livre (Foxy Commerce, docs/10): app criado pela conta da Foxy. Retorno: https://ecommerce.foxy.net.br/app/canais/mercadolivre/retorno
    // Notificações: https://ecommerce.foxy.net.br/webhooks/commerce/mercadolivre (tópico orders_v2)
    'mercadolivre' => [
        'client_id' => env('MERCADOLIVRE_CLIENT_ID'),
        'client_secret' => env('MERCADOLIVRE_CLIENT_SECRET'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
