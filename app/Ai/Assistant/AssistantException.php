<?php

namespace App\Ai\Assistant;

use App\Ai\Plans\AiPlan;
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

    public static function noAiPlan(): self
    {
        return new self('no_ai_plan', 'Este cliente não tem plano de IA ativo. O sistema precisa definir o plano.', 403);
    }

    public static function tierNotInPlan(string $tier, AiPlan $plan): self
    {
        return new self('tier_not_in_plan', "A faixa \"{$tier}\" não faz parte do plano {$plan->name}. Faixas do plano: ".implode(', ', $plan->tiers).'.', 403);
    }

    public static function noCapabilities(): self
    {
        return new self('no_capabilities', 'Este usuário não tem nenhuma função de IA liberada (é preciso ao menos "suporte").', 403);
    }

    public static function creditLimit(): self
    {
        return new self('credit_limit', 'Os créditos de IA deste mês acabaram. É possível comprar créditos extras.', 429);
    }

    public static function userRateLimited(): self
    {
        return new self('rate_limited', 'Muitas mensagens em pouco tempo. Aguarde um instante.', 429);
    }

    public static function unknownTools(array $names): self
    {
        return new self('unknown_tools', 'Tools fora do catálogo atual do perfil: '.implode(', ', $names).'.', 422);
    }

    public static function toolResultsPending(): self
    {
        return new self('tool_results_pending', 'A IA está aguardando os resultados das ferramentas pedidas. Envie-os em /tool-results.', 409);
    }

    public static function noPendingTools(): self
    {
        return new self('no_pending_tools', 'Esta conversa não está aguardando resultados de ferramentas.', 409);
    }

    public static function toolResultsMismatch(array $expectedIds): self
    {
        return new self('tool_results_mismatch', 'Envie exatamente um resultado para cada pedido pendente: '.implode(', ', $expectedIds).'.', 422);
    }

    public static function toolResultTooLarge(string $tool): self
    {
        return new self('tool_result_too_large', "O resultado de {$tool} passou de 30 mil caracteres. Devolva só os campos necessários.", 422);
    }

    public static function providerUnavailable(): self
    {
        return new self('ai_unavailable', 'A IA está indisponível no momento. Tente de novo em instantes.', 503);
    }

    /** Saldo/limite do provedor de IA acabou: o sistema avisa o dono (a pessoa vê a mesma mensagem de indisponível). */
    public static function providerOutOfCredit(): self
    {
        return new self('ai_out_of_credit', 'A IA está indisponível no momento. Tente de novo mais tarde.', 503);
    }
}
