<?php

namespace App\Ai\Assistant;

/**
 * Tools da própria Foxy, resolvidas aqui dentro (o anfitrião nunca as recebe).
 * Nomes começam com "foxy_", prefixo reservado. Cada uma produz um "efeito" que volta ao anfitrião
 * na resposta: encaminhamento ao suporte (escalate) ou mensagem sugerida (drafts).
 */
class InternalTools
{
    public const PREFIX = 'foxy_';

    public const ESCALATE = 'foxy_encaminhar_suporte';

    public const DRAFT = 'foxy_sugerir_mensagem';

    private const ESCALATE_REASONS = ['defeito', 'sem_resposta', 'pedido_da_pessoa'];

    public const DRAFT_CHANNELS = ['whatsapp', 'email', 'texto'];

    /**
     * @param  list<string>  $capabilities  capacidades da conversa; "escrita" libera as mensagens sugeridas
     * @return list<array{name: string, description: string, input_schema: array}>
     */
    public static function definitions(array $capabilities = ['suporte']): array
    {
        $tools = [[
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

        if (in_array('escrita', $capabilities, true)) {
            $tools[] = [
                'name' => self::DRAFT,
                'description' => 'Entrega uma mensagem pronta para a pessoa revisar, copiar e enviar ela mesma (lembrete, confirmação, aniversário, '
                    .'cobrança gentil, retorno, divulgação...). Use sempre que a pessoa pedir um texto para enviar a alguém. Você NÃO envia nada: '
                    .'a mensagem aparece num cartão com o botão "Copiar". Pode chamar mais de uma vez para dar opções.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'canal' => ['type' => 'string', 'enum' => self::DRAFT_CHANNELS, 'description' => 'Onde a mensagem será usada.'],
                        'titulo' => ['type' => 'string', 'description' => 'Rótulo curto da sugestão, ex.: "Lembrete de amanhã".'],
                        'texto' => ['type' => 'string', 'description' => 'A mensagem completa, pronta para enviar, no tom adequado ao canal.'],
                    ],
                    'required' => ['canal', 'texto'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $tools;
    }

    public static function isInternal(string $name): bool
    {
        return str_starts_with($name, self::PREFIX);
    }

    /**
     * Executa uma tool interna.
     *
     * @return array{0: array, 1: ?array} [tool_result para o modelo, efeito para o anfitrião: ['escalate' => ...] | ['draft' => ...] | null]
     */
    public static function run(array $call): array
    {
        return match ($call['name']) {
            self::ESCALATE => self::escalate($call),
            self::DRAFT => self::draft($call),
            default => [ToolResults::error($call['id'], 'Ferramenta desconhecida.'), null],
        };
    }

    /**
     * Junta um efeito ao acumulado da rodada (um encaminhamento por resposta; várias mensagens).
     */
    public static function merge(array $effects, ?array $effect): array
    {
        if (isset($effect['escalate'])) {
            $effects['escalate'] ??= $effect['escalate'];
        }

        if (isset($effect['draft']) && count($effects['drafts']) < 5) {
            $effects['drafts'][] = $effect['draft'];
        }

        return $effects;
    }

    public static function emptyEffects(): array
    {
        return ['escalate' => null, 'drafts' => []];
    }

    private static function escalate(array $call): array
    {
        $reason = $call['input']['motivo'] ?? null;
        $summary = trim((string) ($call['input']['resumo'] ?? ''));

        if (! in_array($reason, self::ESCALATE_REASONS, true) || $summary === '' || mb_strlen($summary) > 2000) {
            return [ToolResults::error($call['id'], 'Entrada inválida: informe "motivo" (defeito, sem_resposta ou pedido_da_pessoa) e um "resumo" de até 2000 caracteres.'), null];
        }

        return [
            ToolResults::ok($call['id'], 'Encaminhamento registrado. Avise a pessoa que o caso foi encaminhado ao suporte e que o sistema vai mostrar como acompanhar.'),
            ['escalate' => ['reason' => $reason, 'summary' => $summary]],
        ];
    }

    private static function draft(array $call): array
    {
        $channel = $call['input']['canal'] ?? null;
        $text = trim((string) ($call['input']['texto'] ?? ''));
        $title = trim((string) ($call['input']['titulo'] ?? ''));

        if (! in_array($channel, self::DRAFT_CHANNELS, true) || $text === '' || mb_strlen($text) > 2000 || mb_strlen($title) > 80) {
            return [ToolResults::error($call['id'], 'Entrada inválida: informe "canal" (whatsapp, email ou texto) e o "texto" (até 2000 caracteres; título até 80).'), null];
        }

        return [
            ToolResults::ok($call['id'], 'Sugestão entregue num cartão com o botão "Copiar". Diga em uma frase curta que a mensagem está pronta abaixo para revisar — nunca diga que foi enviada.'),
            ['draft' => ['channel' => $channel, 'title' => $title !== '' ? $title : null, 'text' => $text]],
        ];
    }
}
