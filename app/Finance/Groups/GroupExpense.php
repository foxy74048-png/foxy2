<?php

namespace App\Finance\Groups;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Despesa do grupo: quem pagou e a parte de cada um (as partes somam o valor). */
class GroupExpense extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_group_expenses';

    protected $fillable = ['group_id', 'description', 'amount_cents', 'date', 'paid_by', 'split_mode', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString(),
        );
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'paid_by');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(GroupShare::class, 'expense_id');
    }
}
