<?php

namespace App\Finance\Assistant;

use App\Platform\Organizations\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** Texto da raposa guardado para não gastar crédito de novo (hoje: as dicas do mês). */
class AiNote extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'finance_ai_notes';

    protected $fillable = ['kind', 'month', 'content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
