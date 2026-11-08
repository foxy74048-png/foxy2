<?php

namespace App\Commerce\Console;

use App\Commerce\Assistant\CommerceFox;
use Illuminate\Console\Command;

class PublishFox extends Command
{
    protected $signature = 'commerce:raposa';

    protected $description = 'Publica na Foxy AI o perfil, o manual e as consultas da raposa do Foxy Commerce (rodar após cada deploy que mude o manual)';

    public function handle(): int
    {
        CommerceFox::publish();
        $this->info('Raposa do Foxy Commerce publicada.');

        return self::SUCCESS;
    }
}
