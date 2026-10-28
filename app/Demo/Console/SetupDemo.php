<?php

namespace App\Demo\Console;

use App\Demo\DemoHost;
use Illuminate\Console\Command;

class SetupDemo extends Command
{
    protected $signature = 'foxy:demo:setup';

    protected $description = 'Configura o negócio de demonstração (organização, chave, perfil, manual e tools) usado pelo console de testes';

    public function handle(): int
    {
        $organization = DemoHost::setup();

        $this->info("Demonstração pronta na organização \"{$organization->name}\". Acesse /console.");

        return self::SUCCESS;
    }
}
