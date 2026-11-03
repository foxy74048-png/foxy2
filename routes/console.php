<?php

use Illuminate\Support\Facades\Schedule;

// Retenção de 30 dias das conversas da Foxy AI (decisão B5). Exige o cron do `schedule:run` no servidor.
Schedule::command('foxy:ai:purge-conversations')->dailyAt('03:00');

// Promoções de IA do Foxy Admin (docs/08): entrega as do mês (as mensais renovam no dia 1º).
Schedule::command('foxy:promocoes:aplicar')->dailyAt('06:00');
