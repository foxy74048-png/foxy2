<?php

namespace App\Ai\Assistant;

/**
 * Tools da própria Foxy, resolvidas aqui dentro (o anfitrião nunca as recebe).
 * Nomes começam com "foxy_", prefixo reservado.
 */
class InternalTools
{
    public const PREFIX = 'foxy_';

    public const ESCALATE = 'foxy_encaminhar_suporte';

    private const ESCALATE_REASONS = ['defeito', 'sem_resposta', 'pedido_da_pessoa'];

    /**
     * @return list<array{name: string, description: string, input_schema: array}>
     */
    public static function definitions(): array
    {
        return [[
            'name' => self::ESCALATE,
            'description' => 'Encaminha o caso para a equipe de suporte humano, com um resumo. Use quando: a pessoa pedir para falar com o suporte; '
                .'o problema parecer defeito do sistema (e não dúvida de uso); ou a resposta não estiver no conhecimento e a pessoa aceitar ser encaminhada. '
                .'Não use para dúvidas que você consegue responder.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'motivo' => ['type' => 'string', 'enum' => self::ESCALATE_REASONS],
                    'resumo' => ['type' => 'string', 'description' => 'Resumo objetivo do problema para a equipe de suporte: o que a pessoa tentou, o que aconteceu e o erro, se houver. Sem dados sensíveis.'],
                ],
                'required' => ['motivo', 'resumo'],
                'additionalProperties' => false,
            ],
        ]];
    }

    public static function isInternal(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    /**
     * Executa uma tool interna.
     *
     * @return array{0: array, 1: ?array} [tool_result para o modelo, escalate para o anfitrião (ou null)]
     */
    public static function run(array $call): array
    {
        if ($call['name'] !== self::ESCALATE) {
            return [ToolResults::error($call['id'], 'Ferramenta desconhecida.'), null];
        }

        $reason = $call['input']['motivo'] ?? null;
        $summary = trim((string) ($call['input']['resumo'] ?? ''));

        if (! in_array($reason, self::ESCALATE_REASONS, true) || $summary === '' || mb_strlen($summary) > 2000) {
            return [ToolResults::error($call['id'], 'Entrada inválida: informe "motivo" (defeito, sem_resposta ou pedido_da_pessoa) e um "resumo" de até 2000 caracteres.'), null];
        }

        return [
            ToolResults::ok($call['id'], 'Encaminhamento registrado. Avise a pessoa que o caso foi encaminhado ao suporte e que o sistema vai mostrar como acompanhar.'),
            ['reason' => $reason, 'summary' => $summary],
        ];
    }
}
