<?php

namespace App\Finance\Goals;

use App\Finance\Transactions\Transaction;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** "Guardei R$ X" (positivo) ou "tirei R$ X" (negativo) numa meta. */
class GoalEntry extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_goal_entries';

    protected $fillable = ['goal_id', 'amount_cents', 'date', 'note', 'transaction_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : Carbon::parse($value)->toDateString(),
        );
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }
}
