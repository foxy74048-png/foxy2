<?php

namespace App\Platform\Console;

use App\Platform\Products\ProductMetricDay;
use Illuminate\Console\Command;

/**
 * Fechamento do dia de cada produto para as métricas do Foxy Admin (docs/08, seção 3.5). Roda de hora em hora;
 * a última gravação do dia é a que fica.
 */
class RecordProductMetrics extends Command
{
    protected $signature = 'foxy:metricas';

    protected $description = 'Grava o fechamento do dia de cada produto (contas, pagantes e MRR) para as métricas do Foxy Admin.';

    public function handle(): int
    {
        $this->info('Fechamento do dia gravado para '.ProductMetricDay::recordToday().' produto(s).');

        return self::SUCCESS;
    }
}
