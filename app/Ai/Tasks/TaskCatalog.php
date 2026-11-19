<?php

namespace App\Ai\Tasks;

/**
 * Tarefas avulsas da Foxy AI (docs/12 §14): um pedido só, sem conversa, com resposta em JSON num formato fixo.
 * Só estas existem — o sistema que pede não manda instrução livre, só os dados de entrada (validados aqui).
 * O texto de instrução de cada tarefa fica fixo e vai em cache (é igual para todos os clientes).
 */
class TaskCatalog
{
    /**
     * @return array{name: string, instructions: string, input: array<string, mixed>, schema: array, max_tokens: int}|null
     */
    public static function get(string $name): ?array
    {
        $tasks = self::all();

        return isset($tasks[$name]) ? ['name' => $name] + $tasks[$name] : null;
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return [
            'finance.categorize' => [
                'instructions' => <<<'TXT'
                Você categoriza movimentos de extrato bancário de uma pessoa ou pequeno negócio no Brasil.
                Recebe uma lista de descrições (como aparecem no extrato) com o tipo (gasto ou recebimento) e a lista de categorias disponíveis (id e nome).
                Para cada descrição, escolha o id da categoria mais provável, do mesmo tipo. Se não der para saber com segurança (nome de pessoa, código sem sentido, Pix genérico), responda null — é melhor não sugerir do que sugerir errado.
                Não invente categorias: use só os ids recebidos. Responda para todas as descrições, na mesma ordem, usando o "index" de cada uma.
                TXT,
                'input' => [
                    'items' => ['required', 'array', 'min:1', 'max:50'],
                    'items.*.index' => ['required', 'integer', 'min:0'],
                    'items.*.description' => ['required', 'string', 'max:255'],
                    'items.*.kind' => ['required', 'in:gasto,recebimento'],
                    'categories' => ['required', 'array', 'min:1', 'max:300'],
                    'categories.*.id' => ['required', 'string', 'max:30'],
                    'categories.*.name' => ['required', 'string', 'max:140'],
                    'categories.*.kind' => ['required', 'in:gasto,recebimento'],
                ],
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'properties' => ['index' => ['type' => 'integer'], 'category_id' => ['type' => ['string', 'null']]],
                            'required' => ['index', 'category_id'],
                            'additionalProperties' => false,
                        ]],
                    ],
                    'required' => ['items'],
                    'additionalProperties' => false,
                ],
                'max_tokens' => 4000,
            ],

            'finance.tips' => [
                'instructions' => <<<'TXT'
                Você é a raposa do Foxy Finanças e escreve um resumo curto do mês de uma pessoa, em português do Brasil, tratando por "você", com tom gentil e direto (sem julgar).
                Recebe os números do mês JÁ CALCULADOS pelo sistema. Não faça contas novas e não cite valor que não esteja nos dados: use os valores exatamente como vieram (ex.: "R$ 1.234,56").
                Escreva 3 frases curtas sobre o que mais chama atenção (o que subiu, gastinhos, assinaturas, quanto guardou, reserva) e 1 sugestão prática e possível para o próximo mês.
                Se os dados forem poucos, diga isso numa frase e sugira importar o extrato.
                TXT,
                'input' => [
                    'month' => ['required', 'string', 'max:40'],
                    'facts' => ['required', 'array', 'max:40'],
                    'facts.*' => ['string', 'max:300'],
                ],
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'sentences' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'suggestion' => ['type' => 'string'],
                    ],
                    'required' => ['sentences', 'suggestion'],
                    'additionalProperties' => false,
                ],
                'max_tokens' => 1500,
            ],

            'finance.parse_message' => [
                'instructions' => <<<'TXT'
                Você lê uma mensagem curta de WhatsApp em que a pessoa conta um gasto ou um recebimento (ex.: "gastei 42,90 no mercado no débito", "recebi 1500 do freela") e devolve os dados para lançar.
                kind: "gasto" ou "recebimento". amount: o valor em reais com ponto decimal (42.9). description: curta, o lugar ou o motivo (ex.: "Mercado"). account: o nome de uma das contas recebidas se a pessoa citou (ou algo claro como "débito" = a conta corrente, "crédito"/"cartão" = o cartão); senão null. date: AAAA-MM-DD se a pessoa disse ("ontem", "dia 10"), usando a data de hoje recebida; senão null.
                Se a mensagem não for um gasto ou recebimento, ou faltar o valor, devolva understood=false.
                TXT,
                'input' => [
                    'text' => ['required', 'string', 'max:500'],
                    'today' => ['required', 'date_format:Y-m-d'],
                    'accounts' => ['nullable', 'array', 'max:30'],
                    'accounts.*' => ['string', 'max:80'],
                ],
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'understood' => ['type' => 'boolean'],
                        'kind' => ['type' => ['string', 'null'], 'enum' => ['gasto', 'recebimento', null]],
                        'amount' => ['type' => ['number', 'null']],
                        'description' => ['type' => ['string', 'null']],
                        'account' => ['type' => ['string', 'null']],
                        'date' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['understood', 'kind', 'amount', 'description', 'account', 'date'],
                    'additionalProperties' => false,
                ],
                'max_tokens' => 800,
            ],
        ];
    }
}
