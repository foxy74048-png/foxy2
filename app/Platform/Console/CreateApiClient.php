<?php

namespace App\Platform\Console;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

class CreateApiClient extends Command
{
    protected $signature = 'foxy:api-client:create
        {organization : ID da organização}
        {name : Nome da credencial (ex.: "Console de testes")}
        {--scope=* : Escopos permitidos; sem informar, todos (*)}';

    protected $description = 'Cria uma chave de API para uma organização (a chave aparece só uma vez)';

    public function handle(): int
    {
        $organization = Organization::find($this->argument('organization'));

        if (! $organization) {
            $this->error('Organização não encontrada.');

            return self::FAILURE;
        }

        $scopes = $this->option('scope') ?: ['*'];
        [$client, $key] = ApiClient::issue($organization, $this->argument('name'), $scopes);

        $this->info("Credencial criada para {$organization->name} (ID {$client->getKey()}).");
        $this->line('Escopos: '.implode(', ', $scopes));
        $this->warn('Guarde a chave agora — ela não será mostrada de novo:');
        $this->line($key);

        return self::SUCCESS;
    }
}
