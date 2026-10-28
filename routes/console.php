<?php

use Illuminate\Support\Facades\Schedule;

// Retenção de 30 dias das conversas da Foxy AI (decisão B5). Exige o cron do `schedule:run` no servidor.
Schedule::command('foxy:ai:purge-conversations')->dailyAt('03:00');
