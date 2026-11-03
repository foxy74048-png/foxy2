<?php

namespace App\Ai\Console;

use App\Ai\Promotions\PromotionEngine;
use Illuminate\Console\Command;

class ApplyPromotions extends Command
{
    protected $signature = 'foxy:promocoes:aplicar';

    protected $description = 'Entrega as promoções de IA que estão valendo (não repete o que já foi entregue).';

    public function handle(PromotionEngine $engine): int
    {
        $this->info('Entregas novas: '.$engine->apply());

        return self::SUCCESS;
    }
}
