<?php

namespace App\Platform\Billing;

use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assinatura de um produto por uma organização. Sem escopo global: o webhook do Stripe chega sem usuário e
 * sem contexto. Sempre filtrar por organização ou pelo id do Stripe.
 *
 * Situações: as do Stripe (active, past_due, canceled, unpaid, incomplete...) ou "trialing" no teste grátis.
 */
class Subscription extends Model
{
    use HasUlids;

    public const FULL = 'full';

    public const READ_ONLY = 'read_only';

    protected $fillable = [
        'organization_id', 'product', 'plan', 'status', 'trial_ends_at', 'current_period_end', 'cancel_at_period_end',
        'founder_at', 'stripe_customer_id', 'stripe_subscription_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'founder_at' => 'datetime',
        ];
    }

    public static function for(Organization $organization, string $product): ?self
    {
        return static::where('organization_id', $organization->getKey())->where('product', $product)->first();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Assinada no Stripe: em dia, tentando cobrar de novo, ou assinada durante o teste grátis (o Stripe só
     * cobra quando o teste acaba e manda "trialing" até lá).
     */
    public function isPaying(): bool
    {
        return $this->stripe_subscription_id !== null && in_array($this->status, ['active', 'past_due', 'trialing'], true);
    }

    /** Assinada, mas a primeira cobrança só acontece quando o teste grátis acabar. */
    public function awaitingFirstCharge(): bool
    {
        return $this->isPaying() && $this->status === 'trialing';
    }

    public function isFounder(): bool
    {
        return $this->founder_at !== null;
    }

    public function inTrial(): bool
    {
        return ! $this->isPaying() && $this->trial_ends_at?->isFuture() === true;
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    /**
     * Uso completo ou só leitura. Os dados nunca são apagados por falta de pagamento.
     */
    public function access(): string
    {
        return $this->isFounder() || $this->isPaying() || $this->inTrial() ? self::FULL : self::READ_ONLY;
    }

    public function trialDaysLeft(): int
    {
        return $this->inTrial() ? (int) ceil(now()->diffInHours($this->trial_ends_at) / 24) : 0;
    }
}
