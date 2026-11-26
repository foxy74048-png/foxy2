<?php

namespace App\Platform\Products;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma mudança de situação ou de MRR de uma conta de produto (métricas do Foxy Admin, docs/08 seção 3.5).
 * "baseline" = início do histórico (a conta já existia quando o histórico começou; o antes não se sabe).
 * Gravado sozinho pela ProductAccount ao salvar.
 */
class ProductAccountEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['integration_id', 'product_account_id', 'from_status', 'to_status', 'from_mrr', 'to_mrr', 'baseline', 'occurred_at'];

    protected function casts(): array
    {
        return ['from_mrr' => 'decimal:2', 'to_mrr' => 'decimal:2', 'baseline' => 'boolean', 'occurred_at' => 'datetime'];
    }
}
