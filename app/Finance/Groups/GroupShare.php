<?php

namespace App\Finance\Groups;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A parte de uma pessoa numa despesa do grupo. */
class GroupShare extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected $table = 'finance_group_shares';

    protected $fillable = ['expense_id', 'member_id', 'amount_cents'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
