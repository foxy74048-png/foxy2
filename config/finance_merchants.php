<?php

/*
| Lista pronta de lojas e serviços conhecidos (docs/12, seção 6, passo 2): palavras da descrição do extrato
| (sem acento, minúsculas) => chave da categoria pronta (DefaultCategories, system_key). Vale para todas as
| carteiras, sem IA e sem custo. A regra da própria carteira sempre vence. A mais longa que bater vence
| ("amazon prime" antes de "amazon").
|
| Só entra o que é quase sempre a mesma coisa para qualquer pessoa. Na dúvida, fica de fora (melhor sem
| sugestão do que sugestão errada).
*/
return [
    // Comer fora
    'ifood' => 'delivery', 'ifd' => 'delivery', 'rappi' => 'delivery', 'aiqfome' => 'delivery', 'ze delivery' => 'delivery', 'james delivery' => 'delivery',
    'mcdonalds' => 'lanche', 'mc donalds' => 'lanche', 'burger king' => 'lanche', 'bk brasil' => 'lanche', 'subway' => 'lanche', 'starbucks' => 'lanche', 'habibs' => 'lanche', 'bobs' => 'lanche', 'kfc' => 'lanche', 'cacau show' => 'lanche',
    'outback' => 'restaurante', 'coco bambu' => 'restaurante', 'madero' => 'restaurante',

    // Mercado
    'carrefour' => 'mercado', 'assai' => 'mercado', 'atacadao' => 'mercado', 'pao de acucar' => 'mercado', 'extra hiper' => 'mercado', 'supermercado' => 'mercado',
    'hipermercado' => 'mercado', 'atacarejo' => 'mercado', 'sams club' => 'mercado', 'makro' => 'mercado', 'dia brasil' => 'mercado', 'oba hortifruti' => 'mercado', 'hortifruti' => 'mercado',
    'savegnago' => 'mercado', 'zaffari' => 'mercado', 'guanabara' => 'mercado', 'mateus' => 'mercado', 'condor' => 'mercado', 'angeloni' => 'mercado', 'bistek' => 'mercado', 'muffato' => 'mercado',

    // Transporte
    'uber' => 'app_transporte', 'uber trip' => 'app_transporte', '99app' => 'app_transporte', '99 pop' => 'app_transporte', '99 tecnologia' => 'app_transporte', 'cabify' => 'app_transporte', 'indriver' => 'app_transporte',
    'posto' => 'combustivel', 'auto posto' => 'combustivel', 'shell' => 'combustivel', 'ipiranga' => 'combustivel', 'petrobras' => 'combustivel', 'br mania' => 'combustivel', 'raizen' => 'combustivel',
    'sem parar' => 'estacionamento', 'conectcar' => 'estacionamento', 'veloe' => 'estacionamento', 'estapar' => 'estacionamento', 'zul digital' => 'estacionamento', 'estacionamento' => 'estacionamento', 'pedagio' => 'estacionamento',
    'bilhete unico' => 'onibus', 'metro' => 'onibus', 'cptm' => 'onibus', 'top sptrans' => 'onibus',

    // Saúde
    'drogasil' => 'farmacia', 'droga raia' => 'farmacia', 'raia drogasil' => 'farmacia', 'drogaria' => 'farmacia', 'farmacia' => 'farmacia', 'pague menos' => 'farmacia', 'panvel' => 'farmacia', 'drogao' => 'farmacia', 'ultrafarma' => 'farmacia', 'nissei' => 'farmacia', 'sao joao farmacias' => 'farmacia',
    'unimed' => 'plano_saude', 'hapvida' => 'plano_saude', 'amil' => 'plano_saude', 'bradesco saude' => 'plano_saude', 'sulamerica saude' => 'plano_saude', 'notredame' => 'plano_saude',
    'smart fit' => 'academia', 'smartfit' => 'academia', 'bluefit' => 'academia', 'gympass' => 'academia', 'wellhub' => 'academia', 'totalpass' => 'academia',

    // Assinaturas
    'netflix' => 'streaming', 'spotify' => 'streaming', 'disney plus' => 'streaming', 'disneyplus' => 'streaming', 'amazon prime' => 'streaming', 'prime video' => 'streaming', 'hbo max' => 'streaming', 'max com' => 'streaming',
    'globoplay' => 'streaming', 'deezer' => 'streaming', 'youtube premium' => 'streaming', 'paramount' => 'streaming', 'apple com bill' => 'apps', 'google play' => 'apps', 'google one' => 'apps', 'icloud' => 'apps', 'microsoft' => 'apps', 'chatgpt' => 'apps', 'openai' => 'apps', 'canva' => 'apps',

    // Moradia (concessionárias)
    'enel' => 'luz', 'cemig' => 'luz', 'light servicos' => 'luz', 'copel' => 'luz', 'celesc' => 'luz', 'coelba' => 'luz', 'neoenergia' => 'luz', 'equatorial' => 'luz', 'cpfl' => 'luz', 'energisa' => 'luz', 'eletropaulo' => 'luz',
    'sabesp' => 'agua', 'copasa' => 'agua', 'sanepar' => 'agua', 'cedae' => 'agua', 'embasa' => 'agua', 'compesa' => 'agua', 'caesb' => 'agua', 'corsan' => 'agua',
    'comgas' => 'gas', 'ultragaz' => 'gas', 'liquigas' => 'gas', 'supergasbras' => 'gas', 'copagaz' => 'gas',
    'vivo' => 'internet', 'claro' => 'internet', 'tim celular' => 'internet', 'oi fibra' => 'internet', 'net servicos' => 'internet', 'sky' => 'internet',

    // Compras
    'mercado livre' => 'casa', 'mercadolivre' => 'casa', 'shopee' => 'casa', 'amazon' => 'casa', 'magalu' => 'casa', 'magazine luiza' => 'casa', 'americanas' => 'casa', 'aliexpress' => 'casa', 'shein' => 'roupas', 'renner' => 'roupas', 'riachuelo' => 'roupas', 'cea' => 'roupas', 'zara' => 'roupas', 'centauro' => 'roupas', 'netshoes' => 'roupas',
    'leroy merlin' => 'casa', 'tok stok' => 'casa', 'casas bahia' => 'casa', 'kabum' => 'eletronicos', 'fast shop' => 'eletronicos',

    // Pets, lazer, cuidados, educação
    'petz' => 'pets', 'cobasi' => 'pets', 'petlove' => 'pets',
    'ingresso com' => 'passeios', 'sympla' => 'passeios', 'cinemark' => 'passeios', 'kinoplex' => 'passeios', 'eventim' => 'passeios',
    'airbnb' => 'viagem', 'booking' => 'viagem', 'decolar' => 'viagem', 'latam' => 'viagem', 'gol linhas' => 'viagem', 'azul linhas' => 'viagem', '123milhas' => 'viagem',
    'steam' => 'hobbies', 'playstation' => 'hobbies', 'xbox' => 'hobbies', 'nintendo' => 'hobbies',
    'o boticario' => 'beleza', 'boticario' => 'beleza', 'natura' => 'beleza', 'sephora' => 'beleza', 'eudora' => 'beleza',
    'udemy' => 'cursos', 'alura' => 'cursos', 'coursera' => 'cursos', 'duolingo' => 'cursos',

    // Taxas
    'tarifa' => 'tarifas', 'cesta de servicos' => 'tarifas', 'pacote servicos' => 'tarifas', 'anuidade' => 'anuidade', 'juros' => 'juros', 'iof' => 'juros', 'multa' => 'juros', 'encargos' => 'juros',

    // Negócio (só vale na carteira do negócio, onde essas categorias existem)
    'das simples' => 'das', 'pgmei' => 'das', 'das mei' => 'das', 'simples nacional' => 'das',
    'stone' => 'maquininha', 'cielo' => 'maquininha', 'pagseguro' => 'maquininha', 'getnet' => 'maquininha', 'sumup' => 'maquininha', 'infinitepay' => 'maquininha',
    'meta ads' => 'marketing', 'facebk' => 'marketing', 'google ads' => 'marketing',
    'correios' => 'frete', 'melhor envio' => 'frete', 'jadlog' => 'frete', 'loggi' => 'frete',
];
