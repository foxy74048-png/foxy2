<?php

namespace App\Ai\Promotions;

use App\Platform\Integrations\Integration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Promoção de IA: perguntas extras de brinde, controladas no Foxy Admin (docs/08, seção 4).
 */
class AiPromotion extends Model
{
    use HasUlids;

    public const AUDIENCES = [
        'novas_contas' => 'Contas novas',
        'todas' => 'Todas as contas ativas e em teste',
        'contas' => 'Contas escolhidas (parceria, presente)',
    ];

    public const RECURRENCES = [
        'uma_vez' => 'Uma vez',
        'mensal' => 'Todo mês, enquanto valer',
    ];

    public const STATUSES = ['ativa' => 'Ativa', 'pausada' => 'Pausada', 'encerrada' => 'Encerrada'];

    protected $fillable = ['integration_id', 'name', 'audience', 'recurrence', 'credits', 'new_account_days', 'plans', 'account_refs', 'starts_at', 'ends_at', 'status', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['plans' => 'array', 'account_refs' => 'array', 'starts_at' => 'date', 'ends_at' => 'date', 'credits' => 'integer', 'new_account_days' => 'integer'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AiPromotionGrant::class);
    }

    /** Valendo hoje: ativa e dentro do período. */
    public function isRunning(): bool
    {
        $today = now()->startOfDay();

        return $this->status === 'ativa'
            && (! $this->starts_at || $this->starts_at->lte($today))
            && (! $this->ends_at || $this->ends_at->gte($today));
    }

    /** "mensal" entrega uma vez por mês; "uma_vez", uma vez só. */
    public function period(): string
    {
        return $this->recurrence === 'mensal' ? now()->format('Y-m') : 'unica';
    }
}
