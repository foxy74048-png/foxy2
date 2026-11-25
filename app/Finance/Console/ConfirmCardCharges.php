<?php

namespace App\Finance\Console;

use App\Finance\Finance;
use App\Finance\Transactions\TransactionService;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;

/**
 * Todo dia: no cartão, o que estava previsto para hoje (assinatura, compra agendada) vira feito, em todas as
 * carteiras. As telas de faturas e o Início também fazem isso ao abrir.
 */
class ConfirmCardCharges extends Command
{
    protected $signature = 'financas:cartoes';

    protected $description = 'Marca como feitas as cobranças previstas no cartão cuja data já chegou';

    public function handle(ContextStore $contexts, TransactionService $service): int
    {
        $total = 0;
        Organization::where('integration_id', Finance::integration()->getKey())->where('status', '!=', \App\Finance\Privacy\WalletEraser::DELETED)->each(function (Organization $organization) use ($contexts, $service, &$total) {
            $total += $contexts->run(ExecutionContext::forSystem($organization), fn () => $service->confirmDueCardCharges());
        });
        $this->info("{$total} cobrança(s) do cartão confirmada(s).");

        return self::SUCCESS;
    }
}
