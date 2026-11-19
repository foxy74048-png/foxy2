<?php

namespace App\Finance\Groups;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Pagamento entre pessoas do grupo ("o Caio passou R$ 120 para a Ana"). */
class GroupPayment extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_group_payments';

    protected $fillable = ['group_id', 'from_member_id', 'to_member_id', 'amount_cents', 'date'];

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

    public function from(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'from_member_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'to_member_id');
    }
}
