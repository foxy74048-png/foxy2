<?php

namespace App\Platform\Memberships;

use App\Models\User;
use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vínculo de uma pessoa (User) com uma organização, com um papel (docs/01, seção 5.3). Sem escopo global:
 * é a partir dele que se descobre a organização da pessoa, antes de existir contexto. Sempre filtrar por
 * usuário ou organização explicitamente. Os papéis e permissões são de cada produto (ex.: config/commerce.php).
 */
class Membership extends Model
{
    use HasUlids;

    public const ACTIVE = 'active';

    public const REMOVED = 'removed';

    protected $fillable = ['organization_id', 'user_id', 'role', 'status', 'invited_by'];

    protected $attributes = ['status' => self::ACTIVE];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
