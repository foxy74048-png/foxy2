<?php

namespace App\Ai\Promotions;

use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma entrega de promoção: quem ganhou, quanto e em qual período (uma por promoção + cliente + período).
 */
class AiPromotionGrant extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ai_promotion_id', 'organization_id', 'period', 'credits'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(AiPromotion::class, 'ai_promotion_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
