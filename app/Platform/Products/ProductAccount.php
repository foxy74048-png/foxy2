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
        'trial' => 'Em teste',
        'founder' => 'Parceiro Fundador',
        'canceled' => 'Cancelada',
        'expired' => 'Vencida',
        'suspended' => 'Suspensa',
        'removed' => 'Removida',
    ];

    /** Situações em que a conta está usando o produto (recebe promoções "todas as contas"). */
    public const LIVE = ['active', 'trial', 'founder'];

    protected $fillable = ['integration_id', 'external_ref', 'name', 'plan', 'status', 'mrr', 'signed_up_at', 'trial_ends_at', 'last_activity_at', 'seen_at'];

    protected function casts(): array
    {
        return [
            'mrr' => 'decimal:2',
            'signed_up_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
