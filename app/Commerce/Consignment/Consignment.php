<?php

namespace App\Commerce\Consignment;

use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Maleta/sacolinha deixada com uma cliente ou revendedora. Aberta → acertada (o que ficou vira pedido). */
class Consignment extends Model
{
    use BelongsToOrganization, HasUlids;

    public const KINDS = ['cliente' => 'Cliente (escolhe em casa)', 'revendedora' => 'Revendedora (vende e ganha comissão)'];

    public const STATUSES = ['aberta' => 'Com a pessoa', 'acertada' => 'Acertada'];

    protected $table = 'commerce_consignments';

    protected $attributes = ['status' => 'aberta', 'kind' => 'cliente', 'commission_percent' => 0];

    protected $fillable = ['number', 'customer_id', 'kind', 'commission_percent', 'sent_on', 'due_on', 'status', 'order_id', 'notes', 'settled_at', 'created_by'];

    protected function casts(): array
    {
        return ['sent_on' => 'date', 'due_on' => 'date', 'settled_at' => 'datetime', 'commission_percent' => 'float', 'number' => 'integer'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConsignmentItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return 'Maleta #'.$this->number.($this->customer ? ' · '.$this->customer->name : '');
    }

    public function isLate(): bool
    {
        return $this->status === 'aberta' && $this->due_on && $this->due_on->lt(today());
    }

    public function pieces(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** Valor das peças pelo preço de venda (o que a pessoa levou). */
    public function valueCents(): int
    {
        return (int) $this->items->sum(fn (ConsignmentItem $i) => $i->price_cents * $i->quantity);
    }
}
