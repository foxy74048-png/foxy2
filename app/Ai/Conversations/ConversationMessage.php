<?php

namespace App\Ai\Conversations;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma mensagem do histórico, guardada exatamente como foi enviada/recebida do provedor.
 */
class ConversationMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['seq', 'role', 'content', 'created_at'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Mensagens da conversa não podem ser alteradas.'));
    }
}
