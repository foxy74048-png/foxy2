<?php

namespace App\Commerce\Console;

use App\Commerce\Accounts\AccountSync;
use App\Commerce\Commerce;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

/**
 * Atualiza as contas do Commerce no Foxy Admin (o teste vence sozinho, sem nenhum evento).
 */
class SyncAccounts extends Command
{
    protected $signature = 'commerce:contas';

    protected $description = 'Atualiza no Foxy Admin a situação de todas as lojas do Foxy Commerce';

    public function handle(): int
    {
        Organization::where('integration_id', Commerce::integration()->getKey())
            ->each(fn (Organization $organization) => AccountSync::sync($organization));

        return self::SUCCESS;
    }
}
