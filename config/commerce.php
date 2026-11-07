<?php

/*
| Foxy Commerce (docs/03 e docs/09). Endereço em config/foxy.php (hosts.commerce).
*/
return [

    // Cadastro aberto ao público. Fica fechado até o lançamento; convites de equipe funcionam sempre.
    'signup_open' => (bool) env('FOXY_COMMERCE_SIGNUP_OPEN', false),

    'trial_days' => 14,

    // Onde ficam as fotos de produto: "public" (servidor, exige storage:link) ou "s3" quando o bucket existir (F4).
    'images_disk' => env('COMMERCE_IMAGES_DISK', 'public'),

    /*
    | Planos (docs/09, fatia 2). PREÇOS E LIMITES PROVISÓRIOS até a decisão D-C4 — mudar aqui e no Stripe.
    | stripe_price = id do preço mensal no Stripe (price_...). Limite null = sem limite.
    | No teste grátis valem os limites de "trial_limits".
    */
    'plans' => [
        'inicial' => [
            'name' => 'Inicial', 'price_cents' => 7900,
            'stripe_price' => env('COMMERCE_STRIPE_PRICE_INICIAL'),
            'limits' => ['users' => 2, 'products' => 300, 'channels' => 1],
        ],
        'profissional' => [
            'name' => 'Profissional', 'price_cents' => 14900,
            'stripe_price' => env('COMMERCE_STRIPE_PRICE_PROFISSIONAL'),
            'limits' => ['users' => 5, 'products' => 2000, 'channels' => 3],
        ],
        'completo' => [
            'name' => 'Completo', 'price_cents' => 24900,
            'stripe_price' => env('COMMERCE_STRIPE_PRICE_COMPLETO'),
            'limits' => ['users' => 15, 'products' => null, 'channels' => null],
        ],
    ],

    'trial_limits' => ['users' => 5, 'products' => 2000, 'channels' => 3],

    'invitation_days' => 7,

    /*
    | Papéis fixos (docs/09, F1). O dono pode tudo; os outros, só o que está na lista.
    */
    'roles' => [
        'owner' => ['label' => 'Dono(a)', 'permissions' => ['*']],
        'manager' => ['label' => 'Gerente', 'permissions' => [
            'commerce.team.view', 'commerce.store.manage', 'commerce.catalog.view', 'commerce.catalog.manage', 'commerce.stock.view', 'commerce.stock.adjust',
            'commerce.orders.manage', 'commerce.orders.cancel', 'commerce.channels.manage', 'commerce.reports.view',
        ]],
        'attendant' => ['label' => 'Atendente', 'permissions' => [
            'commerce.catalog.view', 'commerce.stock.view', 'commerce.orders.manage',
        ]],
    ],

    // Endereços que não podem virar nome de loja (a loja própria fica em ecommerce.foxy.net.br/{slug}).
    'reserved_slugs' => [
        'app', 'entrar', 'sair', 'cadastro', 'senha', 'convite', 'verificar-email', 'webhooks', 'stripe', 'api',
        'admin', 'console', 'core', 'up', 'loja', 'lojas', 'ajuda', 'suporte', 'termos', 'privacidade', 'foxy',
    ],
];
