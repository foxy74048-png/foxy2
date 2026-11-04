<?php

namespace App\Ai\Insights;

use App\Platform\Organizations\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que a raposa anotou numa conversa, de qualquer produto: "ideia" (algo que o sistema não tem) ou "duvida"
 * (pergunta de uso que o manual não responde). Só o resumo, sem nomes de clientes. Lido no Foxy Admin.
 * Sem escopo de organização de propósito: é dado da equipe Foxy, sempre listado por cima de todos os clientes.
 */
class AiInsight extends Model
{
    public const KINDS = ['ideia' => 'Ideias', 'duvida' => 'Dúvidas sem resposta'];

    public const STATUSES = ['nova' => 'Nova', 'avaliando' => 'Avaliando', 'planejada' => 'Vai ser feita', 'feita' => 'Feita', 'descartada' => 'Descartada'];

    protected $fillable = ['organization_id', 'kind', 'summary', 'status', 'page'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
