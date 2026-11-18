<?php

namespace App\Commerce\Insights;

use App\Commerce\Stores\Store;

/**
 * Planilha e PDF de qualquer lista do Commerce: a formatação é da Platform; aqui, o nome do produto e da loja.
 */
class Export extends \App\Platform\Exports\Export
{
    protected string $product = 'Foxy Commerce';

    protected function accountName(): ?string
    {
        return Store::first()?->name;
    }
}
