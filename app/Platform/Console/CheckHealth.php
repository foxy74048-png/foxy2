<?php

namespace App\Platform\Console;

use App\Platform\Maintenance\Health;
use Illuminate\Console\Command;

class CheckHealth extends Command
{
    protected $signature = 'foxy:saude {--sem-aviso : só mostra, não manda e-mail}';

    protected $description = 'Confere banco, disco, backup, fila, marketplaces e certificados; avisa a equipe da Foxy por e-mail';

    public function handle(Health $health): int
    {
        $report = $health->run(! $this->option('sem-aviso'));
        $icons = [Health::OK => '✔', Health::WARN => '!', Health::FAIL => '✖'];

        $this->table(['', 'Verificação', 'Situação'], array_map(fn ($c) => [$icons[$c['status']], $c['label'], $c['detail']], $report['checks']));

        return $report['status'] === Health::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
