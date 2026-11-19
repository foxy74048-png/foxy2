<?php

namespace App\Finance\Debts;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um movimento com uma pessoa. Valor com sinal: positivo aumenta o que ela te deve; negativo, o que você deve
 * (ou o que ela já devolveu). Objeto emprestado tem valor zero e "devolvido em".
 */
class PersonEntry extends Model
{
    use BelongsToOrganization, HasUlids;

    /** tipo => [rótulo, sinal (+ ela te deve mais / − menos), dinheiro sai da conta?] */
    public const KINDS = [
        'emprestei' => ['Emprestei', 1, 'sai'],
        'peguei' => ['Peguei emprestado', -1, 'entra'],
        'devolveu' => ['Me devolveu', -1, 'entra'],
        'paguei' => ['Eu paguei de volta', 1, 'sai'],
        'dividi' => ['Dividimos uma conta', 1, null],
        'objeto' => ['Emprestei um objeto', 0, null],
        // Saldo trazido de um grupo de despesas (6d); o sinal vem no valor.
        'grupo' => ['Despesas em grupo', 1, null],
    ];

    protected $table = 'finance_person_entries';

    protected $fillable = ['contact_id', 'kind', 'amount_cents', 'description', 'date', 'due_date', 'transaction_id', 'expense_transaction_id', 'group_id', 'returned_at', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'returned_at' => 'datetime'];
    }

    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString(),
        );
    }

    protected function dueDate(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null || $v === '' ? null : Carbon::parse($v)->toDateString(),
        );
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function label(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }
}
