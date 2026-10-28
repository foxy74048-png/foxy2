<?php

namespace App\Ai\Console;

use App\Ai\Usage\UsageReport as Report;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

class UsageReport extends Command
{
    protected $signature = 'foxy:ai:usage {--month= : Mês no formato AAAA-MM (padrão: mês atual)}';

    protected $description = 'Uso e custo estimado da Foxy AI por organização e modelo';

    public function handle(): int
    {
        $month = $this->option('month') ?: now()->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->error('Use o formato AAAA-MM.');

            return self::FAILURE;
        }

        $rows = [];
        foreach (Organization::orderBy('name')->get() as $organization) {
            $report = Report::forOrganization($organization->getKey(), $month);

            foreach ($report['by_model'] as $model) {
                $rows[] = [$organization->name, $model['model'], $model['calls'], $model['input_tokens'], $model['cache_read_tokens'], $model['output_tokens'], number_format($model['cost_usd'], 4)];
            }

            if ($report['by_model'] !== []) {
                $rows[] = [$organization->name, 'TOTAL ('.$report['messages'].' mensagens)', '', '', '', '', number_format($report['cost_usd'], 4)];
            }
        }

        $this->info("Foxy AI — uso em {$month} (custo estimado em US$)");
        $this->table(['Organização', 'Modelo', 'Chamadas', 'Entrada', 'Cache', 'Saída', 'Custo'], $rows);

        return self::SUCCESS;
    }
}
