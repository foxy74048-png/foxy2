<?php

namespace App\Platform\Products;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Números gerais que um produto envia junto com as contas (MRR, contas por situação...). Guarda o histórico.
 */
class ProductSnapshot extends Model
{
    use HasUlids;

    protected $fillable = ['integration_id', 'metrics', 'accounts_count', 'taken_at'];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'taken_at' => 'datetime'];
    }
}
