<?php

namespace App\Finance\Console;

use App\Finance\Assistant\FinanceFox;
use Illuminate\Console\Command;

class PublishFox extends Command
{
    protected $signature = 'financas:raposa';

    protected $description = 'Publica na Foxy AI o perfil, o manual e as consultas da raposa do Foxy Finanças (rodar após cada deploy que mude o manual)';

    public function handle(): int
    {
        FinanceFox::publish();
        $this->info('Raposa do Foxy Finanças publicada.');

        return self::SUCCESS;
    }
}
