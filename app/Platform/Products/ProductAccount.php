<?php

namespace App\Platform\Products;

use App\Platform\Integrations\Integration;
use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma conta de um produto Foxy (ex.: um studio no Foxy Studio), como o produto informou no último retrato.
 * Liga-se ao cliente da Foxy AI pelo mesmo external_ref.
 */
class ProductAccount extends Model
{
    use HasUlids;

    public const STATUSES = [
        'active' => 'Ativa (pagante)',
        'free' => 'Plano grátis',
        'trial' => 'Em teste',
        'founder' => 'Parceiro Fundador',
        'canceled' => 'Cancelada',
        'expired' => 'Vencida',
        'suspended' => 'Suspensa',
        'removed' => 'Removida',
    ];

    /** Situações em que a conta está usando o produto (recebe promoções "todas as contas"). */
    public const LIVE = ['active', 'free', 'trial', 'founder'];

    protected $fillable = ['integration_id', 'external_ref', 'name', 'plan', 'status', 'mrr', 'signed_up_at', 'trial_ends_at', 'last_activity_at', 'activated_at', 'seen_at', 'contact_name', 'email', 'whatsapp', 'health', 'problems', 'usage', 'fox_credits', 'admin_url'];

    protected function casts(): array
    {
        return [
            'mrr' => 'decimal:2',
            'signed_up_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'activated_at' => 'datetime',
            'seen_at' => 'datetime',
            'email_opt_out_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
            'problems' => 'array',
            'usage' => 'array',
            'fox_credits' => 'integer',
        ];
    }

    /**
     * Histórico para as métricas do Foxy Admin (docs/08, seção 3.5): conta nova ou mudança de situação/MRR vira
     * um registro. Vale para o retrato do Studio e para as contas do Commerce e do Finanças (gravam direto).
     */
    protected static function booted(): void
    {
        static::saved(function (ProductAccount $account) {
            if (! $account->wasRecentlyCreated && ! $account->wasChanged(['status', 'mrr'])) {
                return;
            }

            ProductAccountEvent::create([
                'integration_id' => $account->integration_id,
                'product_account_id' => $account->id,
                'from_status' => $account->wasRecentlyCreated ? null : $account->getOriginal('status'),
                'to_status' => $account->status,
                'from_mrr' => $account->wasRecentlyCreated ? 0 : (float) $account->getOriginal('mrr'),
                'to_mrr' => (float) $account->mrr,
                'occurred_at' => now(),
            ]);
        });
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProductAccountEvent::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** Cliente da Foxy AI desta conta (se a conta já usou a raposa). */
    public function organization(): ?Organization
    {
        return Organization::where('integration_id', $this->integration_id)->where('external_ref', $this->external_ref)->first();
    }

    public const HEALTH = ['active' => 'Usando bem', 'cooling' => 'Esfriando', 'idle' => 'Parada', 'never' => 'Nunca engrenou'];

    public function healthLabel(): ?string
    {
        return $this->health ? (self::HEALTH[$this->health] ?? $this->health) : null;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
