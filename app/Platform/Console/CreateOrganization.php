<?php

namespace App\Platform\Console;

use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

class CreateOrganization extends Command
{
    protected $signature = 'foxy:organization:create {name : Nome da organização}';

    protected $description = 'Cria uma organização (uma por sistema consumidor)';

    public function handle(): int
    {
        $organization = Organization::create(['name' => $this->argument('name')]);

        $this->info("Organização criada: {$organization->name}");
        $this->line("ID: {$organization->getKey()}");

        return self::SUCCESS;
    }
}
