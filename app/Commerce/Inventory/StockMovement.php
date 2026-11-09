<?php

namespace App\Commerce\Inventory;

use App\Commerce\Catalog\Variant;
use App\Models\User;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Movimento de estoque (só inclusão): é a história que explica o saldo.
 */
class StockMovement extends Model
{
    use BelongsToOrganization, HasUlids;

    public const UPDATED_AT = null;

    public const TYPES = [
        'entrada' => 'Entrada',
        'ajuste' => 'Ajuste (contagem)',
        'saida' => 'Saída manual',
        'venda' => 'Venda',
        'cancelamento' => 'Cancelamento',
        'devolucao' => 'Devolução',
        'troca' => 'Troca (peça voltou)',
        'reserva' => 'Reserva',
        'liberacao' => 'Reserva liberada',
    ];

    protected $table = 'commerce_stock_movements';

    protected $fillable = ['variant_id', 'type', 'quantity', 'reserved_change', 'on_hand_after', 'order_id', 'reason', 'user_id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Movimento de estoque não pode ser alterado.'));
        static::deleting(fn () => throw new LogicException('Movimento de estoque não pode ser apagado.'));
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
