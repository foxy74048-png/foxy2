<?php

namespace App\Finance\Console;

use App\Finance\Accounts\AccountSync;
use App\Finance\Finance;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

/**
 * Atualiza as carteiras do Finanças no Foxy Admin (contagens e última atividade; nunca valores).
 */
class SyncAccounts extends Command
{
    protected $signature = 'financas:contas';

    protected $description = 'Atualiza no Foxy Admin a situação de todas as carteiras do Foxy Finanças';

    public function handle(): int
    {
        Organization::where('integration_id', Finance::integration()->getKey())
            ->each(fn (Organization $organization) => AccountSync::sync($organization));

        return self::SUCCESS;
    }
}
