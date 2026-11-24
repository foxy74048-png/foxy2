<?php

namespace App\Finance\Console;

use App\Finance\Finance;
use App\Finance\Rewards\Rewards;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use Illuminate\Console\Command;
use Throwable;

/**
 * Todo dia: fecha o mês que acabou (pontos, resultado dos desafios) e confere as conquistas, em todas as
 * carteiras. A tela de Conquistas também faz isso ao abrir. Rodar de novo não duplica nada.
 */
class EvaluateRewards extends Command
{
    protected $signature = 'financas:conquistas';

    protected $description = 'Avalia o mês que fechou e as conquistas de cada carteira do Foxy Finanças';

    public function handle(ContextStore $contexts): int
    {
        $count = 0;
        Organization::where('integration_id', Finance::integration()->getKey())->each(function (Organization $organization) use ($contexts, &$count) {
            try {
                $contexts->run(ExecutionContext::forSystem($organization), fn () => app(Rewards::class)->catchUp());
                $count++;
            } catch (Throwable $e) {
                report($e);
            }
        });
        $this->info("{$count} carteira(s) avaliada(s).");

        return self::SUCCESS;
    }
}
