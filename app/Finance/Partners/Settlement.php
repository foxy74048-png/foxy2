<?php

namespace App\Finance\Partners;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Acerto direto entre sócios, fora do caixa do negócio: "o Bruno pagou R$ 400 à Ana". Para a conta do acerto,
 * é como se o Bruno tivesse tirado R$ 400 a menos e a Ana R$ 400 a mais.
 */
class Settlement extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_partner_settlements';

    protected $fillable = ['from_partner_id', 'to_partner_id', 'amount_cents', 'date', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($v) => $v === null ? null : Carbon::parse($v)->startOfDay(),
            set: fn ($v) => $v === null ? null : Carbon::parse($v)->toDateString(),
        );
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'from_partner_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'to_partner_id');
    }
}
