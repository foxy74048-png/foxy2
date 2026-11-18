<?php

/*
| Foxy Finanças (docs/11 e docs/12). Endereço em config/foxy.php (hosts.financas).
*/
return [

    // Cadastro aberto ao público. Fica fechado até o lançamento (D-F7); convites para uma carteira funcionam sempre.
    'signup_open' => (bool) env('FOXY_FINANCAS_SIGNUP_OPEN', false),

    'invitation_days' => 7,

    // Tipos de carteira: a pessoal (ou da casa) e a do negócio (MEI, com ou sem sócios).
    'wallet_kinds' => [
        'pessoal' => 'Pessoal / casa',
        'negocio' => 'Negócio (MEI ou com sócios)',
    ],

    // Quantas carteiras uma pessoa pode criar (as que ela recebe por convite não contam).
    'max_owned_wallets' => 5,

    // Pessoas numa carteira (contando os convites pendentes). Provisório até os planos da fatia 10, quando
    // passa a depender do plano (Compartilhado: até 4).
    'max_people' => 4,

    /*
    | Papéis fixos (docs/12, seção 10). O dono pode tudo; os outros, só o que está na lista.
    */
    'roles' => [
        'owner' => ['label' => 'Dono(a)', 'permissions' => ['*']],
        'member' => ['label' => 'Membro', 'permissions' => [
            'finance.view', 'finance.people.view', 'finance.transactions.manage', 'finance.accounts.manage', 'finance.imports.manage', 'finance.reports.view',
        ]],
        'viewer' => ['label' => 'Só vê', 'permissions' => [
            'finance.view', 'finance.people.view', 'finance.reports.view',
        ]],
    ],

];
