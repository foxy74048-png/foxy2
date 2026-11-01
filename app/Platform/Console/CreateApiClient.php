<?php

namespace App\Platform\Console;

use App\Platform\ApiClients\ApiClient;
use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

class CreateApiClient extends Command
{
    protected $signature = 'foxy:api-client:create
        {integration : ID do sistema (integração)}
        {name : Nome da credencial (ex.: "Foxy Agenda — produção")}
        {--organization= : Prende a chave a um único cliente (sem isso, a chave é do sistema)}
        {--scope=* : Escopos permitidos; sem informar, todos (*)}';

    protected $description = 'Cria uma chave de API para um sistema (a chave aparece só uma vez)';

    public function handle(): int
    {
        $integration = Integration::find($this->argument('integration'));

        if (! $integration) {
            $this->error('Sistema (integração) não encontrado.');

            return self::FAILURE;
        }

        $organization = null;
        if ($this->option('organization')) {
            $organization = Organization::where('integration_id', $integration->id)->find($this->option('organization'));

            if (! $organization) {
                $this->error('Cliente não encontrado neste sistema.');

                return self::FAILURE;
            }
        }

        $scopes = $this->option('scope') ?: ['*'];
        [$client, $key] = ApiClient::issue($integration, $this->argument('name'), $scopes, $organization);

        $this->info("Credencial criada para {$integration->name}".($organization ? " — cliente {$organization->name}" : ' (todos os clientes)')." (ID {$client->getKey()}).");
        $this->line('Escopos: '.implode(', ', $scopes));
        $this->warn('Guarde a chave agora — ela não será mostrada de novo:');
        $this->line($key);

        return self::SUCCESS;
    }
}
