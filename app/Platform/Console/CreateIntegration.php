<?php

namespace App\Platform\Console;

use App\Platform\Integrations\Integration;
use Illuminate\Console\Command;

class CreateIntegration extends Command
{
    protected $signature = 'foxy:integration:create {name : Nome do sistema (ex.: Foxy Agenda)}';

    protected $description = 'Cadastra um sistema Foxy que vai usar o Core (os clientes dele são criados pela API)';

    public function handle(): int
    {
        $integration = Integration::create(['name' => $this->argument('name')]);

        $this->info("Sistema cadastrado: {$integration->name}");
        $this->line("ID: {$integration->getKey()}");

        return self::SUCCESS;
    }
}
