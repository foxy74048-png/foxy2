<?php

namespace App\Finance\Transactions;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Lançamento: gasto, recebimento ou transferência entre contas (sai de account_id, entra em to_account_id).
 * Valor sempre positivo, em centavos. "previsto" = ainda não aconteceu (conta futura, repetição); não entra
 * no saldo até ser confirmado. Parcelas e repetições ligadas por series_id. Só TransactionService grava.
 */
class Transaction extends Model
{
    use BelongsToOrganization, HasUlids, SoftDeletes;

    public const EXPENSE = 'gasto';

    public const INCOME = 'recebimento';

    public const TRANSFER = 'transferencia';

    public const KINDS = ['gasto' => 'Gasto', 'recebimento' => 'Recebimento', 'transferencia' => 'Transferência'];

    public const DONE = 'efetivado';

    public const PLANNED = 'previsto';

    public const STATUSES = ['efetivado' => 'Feito', 'previsto' => 'Previsto'];

    public const SOURCES = ['manual' => 'Digitado', 'whatsapp' => 'WhatsApp', 'extrato' => 'Extrato', 'planilha' => 'Planilha', 'socio' => 'Sócios'];

    protected $table = 'finance_transactions';

    protected $fillable = [
        'account_id', 'to_account_id', 'kind', 'amount_cents', 'date', 'description', 'category_id', 'notes', 'status',
        'invoice_month', 'series_id', 'series_kind', 'installment', 'installments', 'source', 'created_by',
    ];

    protected $attributes = ['status' => self::DONE, 'source' => 'manual'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer', 'notes' => 'encrypted',
            'installment' => 'integer', 'installments' => 'integer',
        ];
    }

    /**
     * A data fica gravada só como "Y-m-d" (o cast "date" do Laravel grava com hora no SQLite, e aí
     * "date <= '2026-11-25'" deixaria o dia 25 de fora). Lida sempre como Carbon no começo do dia.
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeDone(Builder $query): void
    {
        $query->where('status', self::DONE);
    }

    public function isTransfer(): bool
    {
        return $this->kind === self::TRANSFER;
    }

    public function isPlanned(): bool
    {
        return $this->status === self::PLANNED;
    }

    /** "3/10" nas parcelas, "todo mês" na repetição. */
    public function seriesLabel(): ?string
    {
        return match ($this->series_kind) {
            'parcelas' => "{$this->installment}/{$this->installments}",
            'mensal' => 'todo mês',
            default => null,
        };
    }

    /** Valor com sinal, do ponto de vista de uma conta (para saldo e extrato da conta). */
    public function signedFor(string $accountId): int
    {
        return match (true) {
            $this->kind === self::INCOME => $this->amount_cents,
            $this->kind === self::TRANSFER && $this->to_account_id === $accountId => $this->amount_cents,
            default => -$this->amount_cents,
        };
    }
}
