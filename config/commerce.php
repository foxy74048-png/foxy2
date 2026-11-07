<?php

/*
| Foxy Commerce (docs/03 e docs/09). Endereço em config/foxy.php (hosts.commerce).
*/
return [

    // Cadastro aberto ao público. Fica fechado até o lançamento; convites de equipe funcionam sempre.
    'signup_open' => (bool) env('FOXY_COMMERCE_SIGNUP_OPEN', false),

    'trial_days' => 14,

    'invitation_days' => 7,

    /*
    | Papéis fixos (docs/09, F1). O dono pode tudo; os outros, só o que está na lista.
    */
    'roles' => [
        'owner' => ['label' => 'Dono(a)', 'permissions' => ['*']],
        'manager' => ['label' => 'Gerente', 'permissions' => [
            'commerce.team.view', 'commerce.store.manage', 'commerce.catalog.manage', 'commerce.stock.adjust',
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
