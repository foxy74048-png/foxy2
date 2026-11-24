<?php

namespace App\Finance\WhatsApp;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O número de WhatsApp de uma pessoa (é da pessoa, não da carteira: vale para todas as carteiras dela).
 * Só conta depois de confirmado pelo código enviado ao próprio número.
 */
class PhoneLink extends Model
{
    use HasUlids;

    protected $table = 'finance_whatsapp_links';

    protected $fillable = ['user_id', 'phone', 'code_hash', 'code_expires_at', 'code_attempts', 'verified_at', 'default_organization_id', 'last_organization_id', 'last_transaction_id', 'last_at', 'challenge_reminders'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['code_expires_at' => 'datetime', 'verified_at' => 'datetime', 'last_at' => 'datetime', 'code_attempts' => 'integer', 'challenge_reminders' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeVerified(Builder $query): void
    {
        $query->whereNotNull('verified_at');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function isPending(): bool
    {
        return ! $this->isVerified() && $this->code_hash !== null && $this->code_expires_at?->isFuture();
    }

    /** (11) 98765-4321 */
    public function displayPhone(): string
    {
        $local = str_starts_with($this->phone, '55') ? substr($this->phone, 2) : $this->phone;

        return strlen($local) >= 10 ? '('.substr($local, 0, 2).') '.substr($local, 2, -4).'-'.substr($local, -4) : $this->phone;
    }
}
