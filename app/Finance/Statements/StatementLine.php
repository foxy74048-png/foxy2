<?php

namespace App\Finance\Statements;

use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Transactions\Transaction;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um movimento do extrato esperando decisão: lançar (vira lançamento), conciliar (já estava lançado à mão ou
 * pelo WhatsApp), dividir em partes, ignorar. Valor com sinal: positivo entrou, negativo saiu.
 */
class StatementLine extends Model
{
    use BelongsToOrganization, HasUlids;

    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const MATCHED = 'matched';

    public const SPLIT = 'split';

    public const IGNORED = 'ignored';

    public const STATUSES = ['pending' => 'Para revisar', 'created' => 'Lançada', 'matched' => 'Conciliada', 'split' => 'Dividida', 'ignored' => 'Ignorada'];

    protected $table = 'finance_statement_lines';

    protected $fillable = [
        'import_id', 'account_id', 'date', 'time', 'description', 'amount_cents', 'dedupe_key', 'status',
        'transaction_id', 'split_details', 'suggested_category_id', 'suggestion_origin', 'resolved_at', 'hidden_at',
    ];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'split_details' => 'array', 'resolved_at' => 'datetime', 'hidden_at' => 'datetime'];
    }

    /** Só "Y-m-d" no banco (igual ao lançamento), lida como Carbon. */
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

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(StatementImport::class, 'import_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'suggested_category_id');
    }

    public function isIncome(): bool
    {
        return $this->amount_cents > 0;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Tipo de lançamento que esta linha vira. */
    public function kind(): string
    {
        return $this->isIncome() ? Transaction::INCOME : Transaction::EXPENSE;
    }
}
