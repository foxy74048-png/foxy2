<?php

namespace App\Platform\Products;

use App\Platform\Integrations\Integration;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido do Foxy Admin para um produto (estender teste, suspender, responder chamado...). O produto busca os
 * pendentes (GET /api/v1/product-commands), executa com as regras dele e devolve o resultado. Assim o Core nunca
 * mexe no banco do produto (docs/08, seção 3.1).
 */
class ProductCommand extends Model
{
    use HasUlids;

    /** Tipos que os produtos entendem, com o texto mostrado no Admin. */
    public const TYPES = [
        'account.extend_trial' => 'Estender o teste',
        'account.suspend' => 'Suspender a conta',
        'account.unsuspend' => 'Reativar a conta',
        'account.founder' => 'Parceiro Fundador',
        'account.fox_credits' => 'Liberar a raposa',
        'support.reply' => 'Responder chamado',
    ];

    public const STATUSES = ['pendente' => 'Aguardando o produto', 'enviado' => 'Com o produto', 'feito' => 'Feito', 'falhou' => 'Não deu certo'];

    protected $fillable = ['integration_id', 'product_account_id', 'type', 'payload', 'status', 'result', 'created_by', 'picked_at', 'done_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'picked_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ProductAccount::class, 'product_account_id');
    }

    public function describe(): string
    {
        $p = $this->payload ?? [];

        return (self::TYPES[$this->type] ?? $this->type).match ($this->type) {
            'account.extend_trial' => ' em '.($p['days'] ?? '?').' dia(s)',
            'account.suspend' => filled($p['reason'] ?? null) ? ': '.$p['reason'] : '',
            'account.founder' => ! empty($p['active']) ? ': ativar' : ': desativar',
            'account.fox_credits' => ': '.((int) ($p['credits'] ?? 0) > 0 ? number_format((int) $p['credits'], 0, ',', '.').' perguntas/mês' : 'tirar a liberação'),
            default => '',
        };
    }
}
