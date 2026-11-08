<?php

use Illuminate\Support\Facades\Schedule;

// Retenção de 30 dias das conversas da Foxy AI (decisão B5). Exige o cron do `schedule:run` no servidor.
Schedule::command('foxy:ai:purge-conversations')->dailyAt('03:00');

// Promoções de IA do Foxy Admin (docs/08): entrega as do mês (as mensais renovam no dia 1º).
Schedule::command('foxy:promocoes:aplicar')->dailyAt('06:00');

// Comunicados do Foxy Admin (docs/08, fase 2): uma leva por minuto (e-mail e WhatsApp com pausa).
Schedule::command('foxy:comunicados:enviar')->everyMinute()->withoutOverlapping(10);

// Foxy Commerce (docs/09): ações do Foxy Admin nas lojas e situação das contas (o teste vence sozinho).
Schedule::command('commerce:comandos')->everyMinute()->withoutOverlapping(10);
Schedule::command('commerce:contas')->hourly();
Schedule::command('commerce:reservas')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('commerce:canais')->everyTenMinutes()->withoutOverlapping(15);

// Fila (decisão F5): um worker por minuto que sai quando a fila esvazia. Publicação de anúncios e envio de
// estoque aos marketplaces passam por aqui. Serviço systemd dedicado quando o volume pedir.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')->everyMinute()->withoutOverlapping(2);
