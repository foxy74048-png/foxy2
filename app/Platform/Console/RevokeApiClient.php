<?php

namespace App\Platform\Console;

use App\Platform\ApiClients\ApiClient;
use Illuminate\Console\Command;

class RevokeApiClient extends Command
{
    protected $signature = 'foxy:api-client:revoke {id : ID da credencial}';

    protected $description = 'Revoga uma chave de API';

    public function handle(): int
    {
        $client = ApiClient::find($this->argument('id'));

        if (! $client) {
            $this->error('Credencial não encontrada.');

            return self::FAILURE;
        }

        $client->revoke();
        $this->info("Credencial {$client->name} revogada.");

        return self::SUCCESS;
    }
}
