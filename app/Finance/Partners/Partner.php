<?php

namespace App\Finance\Partners;

use App\Models\User;
use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sócio da carteira do negócio, com a parte dele (share_bp: 5000 = 50%). Pode ou não ter login. */
class Partner extends Model
{
    use BelongsToOrganization, HasUlids;

    /** Movimento de sócio => [rótulo, tipo de lançamento]. Aporte entra no caixa; os outros saem. */
    public const KINDS = [
        'pro_labore' => ['Pró-labore', 'gasto'],
        'retirada' => ['Retirada', 'gasto'],
        'distribuicao' => ['Distribuição de lucro', 'gasto'],
        'aporte' => ['Aporte (colocou dinheiro)', 'recebimento'],
    ];

    protected $table = 'finance_partners';

    protected $fillable = ['user_id', 'name', 'share_bp', 'mirror_organization_id', 'mirror_account_id', 'archived_at'];

    protected function casts(): array
    {
        return ['share_bp' => 'integer', 'archived_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shareLabel(): string
    {
        return rtrim(rtrim(number_format($this->share_bp / 100, 2, ',', '.'), '0'), ',').'%';
    }
}
