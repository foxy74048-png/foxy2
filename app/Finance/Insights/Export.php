<?php

namespace App\Finance\Insights;

use App\Finance\Finance;
use App\Finance\Wallets\Wallet;

/**
 * Planilha e PDF de qualquer lista do Finanças: a formatação é da Platform; aqui, o produto e a carteira.
 */
class Export extends \App\Platform\Exports\Export
{
    protected string $product = Finance::NAME;

    protected function accountName(): ?string
    {
        return Wallet::first()?->name;
    }
}
