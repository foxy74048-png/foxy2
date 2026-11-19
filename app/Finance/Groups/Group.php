<?php

namespace App\Finance\Groups;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de despesas (viagem, república, festa). O token abre o resumo só de leitura, sem login. */
class Group extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_groups';

    protected $fillable = ['name', 'token', 'closed_at', 'sent_to_debts_at'];

    protected function casts(): array
    {
        return ['closed_at' => 'datetime', 'sent_to_debts_at' => 'datetime'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class)->orderByDesc('is_me')->orderBy('name');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(GroupExpense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(GroupPayment::class);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }
}
