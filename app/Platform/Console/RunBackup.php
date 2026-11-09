<?php

namespace App\Platform\Console;

use App\Platform\Maintenance\Backup;
use App\Platform\Maintenance\Health;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'foxy:backup';

    protected $description = 'Cópia de segurança do banco e dos arquivos enviados (guarda os últimos dias; ver FOXY_BACKUP_PATH)';

    public function handle(Backup $backup, Health $health): int
    {
        $result = $backup->run();

        if (! $result['ok']) {
            $this->error('Backup falhou: '.$result['error']);
            $health->run(); // avisa a equipe na hora

            return self::FAILURE;
        }

        foreach ($result['files'] as $file) {
            $this->line(sprintf('%s (%s MB)', $file['name'], number_format($file['bytes'] / 1024 ** 2, 1, ',', '.')));
        }
        $this->info('Backup feito em '.$backup->path().($result['offsite'] ? ' e copiado para '.$result['offsite'] : '').'.');

        return self::SUCCESS;
    }
}
