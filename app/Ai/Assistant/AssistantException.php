<?php

namespace App\Ai\Assistant;

use RuntimeException;

/**
 * Situação esperada que vira erro da API com código próprio (ver docs/05-arquitetura.md, seção 4).
 */
class AssistantException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function knowledgeMissing(): self
    {
        return new self('knowledge_missing', 'Publique o conhecimento do perfil antes de abrir conversas.', 422);
    }

    public static function conversationFull(int $max): self
    {
        return new self('conversation_full', "A conversa chegou ao limite de {$max} mensagens. Abra uma nova conversa.", 409);
    }

    public static function conversationBusy(): self
    {
        return new self('conversation_busy', 'Esta conversa já está processando outra mensagem ou aguarda resultados de ferramentas.', 409);
    }

    public static function conversationExpired(): self
    {
        return new self('conversation_expired', 'Esta conversa expirou. Abra uma nova conversa.', 410);
    }

    public static function usageLimit(): self
    {
        return new self('usage_limit', 'O limite de uso do mês foi atingido.', 429);
    }

    public static function userRateLimited(): self
    {
        return new self('rate_limited', 'Muitas mensagens em pouco tempo. Aguarde um instante.', 429);
    }

    public static function providerUnavailable(): self
    {
        return new self('ai_unavailable', 'A IA está indisponível no momento. Tente de novo em instantes.', 503);
    }
}
