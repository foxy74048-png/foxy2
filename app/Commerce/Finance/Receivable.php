<?php

namespace App\Commerce\Finance;

use App\Commerce\Orders\Customer;
use App\Commerce\Orders\Order;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conta a receber: parcela do crediário/fiado de um pedido ou lançamento avulso. Recebimento é manual
 * (o dinheiro vai direto para a loja); os lembretes por WhatsApp ficam anotados em "reminders".
 */
class Receivable extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['aberto' => 'Em aberto', 'recebido' => 'Recebido', 'cancelado' => 'Cancelado'];

    protected $table = 'commerce_receivables';

    protected $fillable = [
        'customer_id', 'order_id', 'description', 'amount_cents', 'due_date', 'installment', 'installments', 'status',
        'received_at', 'received_cents', 'payment_method', 'notes', 'reminders', 'created_by',
    ];

    /** Nasce em aberto (sem depender do padrão do banco, que o modelo só veria ao recarregar). */
    protected $attributes = ['status' => 'aberto'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'received_at' => 'datetime', 'reminders' => 'array', 'amount_cents' => 'integer', 'received_cents' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Vencimento guardado só como data (AAAA-MM-DD): comparações iguais no PostgreSQL e no SQLite. */
    public function setDueDateAttribute(mixed $value): void
    {
        $this->attributes['due_date'] = $value === null ? null : Carbon::parse($value)->toDateString();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'aberto');
    }

    public function isOpen(): bool
    {
        return $this->status === 'aberto';
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->lt(today());
    }

    public function daysLate(): int
    {
        return $this->isOverdue() ? (int) $this->due_date->diffInDays(today()) : 0;
    }

    /** "Parcela 2/3" (vazio quando não é parcelado). */
    public function installmentLabel(): string
    {
        return $this->installments > 1 ? "Parcela {$this->installment}/{$this->installments}" : '';
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Vencida' : (self::STATUSES[$this->status] ?? $this->status);
    }
}
