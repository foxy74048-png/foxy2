<?php

namespace App\Platform\Products;

use App\Platform\Integrations\Integration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chamado de suporte de um produto, como o produto informou. A resposta sai pelo Admin como comando
 * (support.reply) e o produto grava e avisa o cliente.
 */
class ProductTicket extends Model
{
    use HasUlids;

    public const STATUSES = ['open' => 'Aberto', 'answered' => 'Respondido', 'closed' => 'Fechado'];

    protected $fillable = ['integration_id', 'product_account_id', 'external_id', 'subject', 'category', 'priority', 'status', 'message', 'admin_response', 'opened_at', 'responded_at'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'responded_at' => 'datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ProductAccount::class, 'product_account_id');
    }
}
