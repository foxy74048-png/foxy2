<?php

namespace App\Platform\Memberships;

use App\Models\User;
use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Convite para entrar numa organização. O token só existe no link do e-mail; aqui fica o hash.
 */
class Invitation extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    /**
     * Cria o convite (substitui um pendente para o mesmo e-mail) e devolve [convite, token].
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Organization $organization, string $email, string $role, User $by, int $days): array
    {
        $email = Str::lower(trim($email));
        $token = Str::random(48);

        static::where('organization_id', $organization->getKey())->where('email', $email)->whereNull('accepted_at')->delete();

        $invitation = static::create([
            'organization_id' => $organization->getKey(),
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $by->getKey(),
            'expires_at' => now()->addDays($days),
        ]);

        return [$invitation, $token];
    }

    public static function findPending(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
