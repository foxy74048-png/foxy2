<?php

namespace App\Finance\Groups;

use App\Finance\Debts\Contact;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pessoa do grupo. "Você" (is_me) é a dona da carteira; os outros podem estar ligados a "Quem me deve". */
class GroupMember extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_group_members';

    protected $fillable = ['group_id', 'contact_id', 'name', 'is_me'];

    protected function casts(): array
    {
        return ['is_me' => 'boolean'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function label(): string
    {
        return $this->is_me ? 'Você' : $this->name;
    }
}
