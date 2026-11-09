<?php

namespace App\Commerce\Finance;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Conta a pagar da loja (fornecedor, aluguel, frete...). Repetição mensal e parcelas ligadas por series_id. */
class Payable extends Model
{
    use BelongsToOrganization, HasUlids;

    public const STATUSES = ['aberto' => 'Em aberto', 'pago' => 'Pago', 'cancelado' => 'Cancelado'];

    public const CATEGORIES = [
        'fornecedor' => 'Fornecedor (mercadoria)', 'aluguel' => 'Aluguel', 'frete' => 'Frete e envios', 'embalagem' => 'Embalagens',
        'marketing' => 'Marketing e anúncios', 'taxas' => 'Taxas e tarifas', 'impostos' => 'Impostos', 'pessoal' => 'Pessoal',
        'sistemas' => 'Sistemas e assinaturas', 'outros' => 'Outros',
    ];

    protected $table = 'commerce_payables';

    protected $fillable = [
        'description', 'supplier', 'category', 'amount_cents', 'due_date', 'status', 'paid_at', 'paid_cents', 'payment_method',
        'notes', 'series_id', 'installment', 'installments', 'created_by',
    ];

    /** Nasce em aberto (sem depender do padrão do banco, que o modelo só veria ao recarregar). */
    protected $attributes = ['status' => 'aberto'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'datetime', 'amount_cents' => 'integer', 'paid_cents' => 'integer'];
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

    public function installmentLabel(): string
    {
        return $this->installments > 1 ? "{$this->installment}/{$this->installments}" : '';
    }

    public function statusLabel(): string
    {
        return $this->isOverdue() ? 'Vencida' : (self::STATUSES[$this->status] ?? $this->status);
    }
}
