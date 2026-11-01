<?php

namespace App\Demo;

use Carbon\CarbonImmutable;

/**
 * Negócio de mentira para validar a Foxy AI sem nenhum sistema real (docs/02-foxy-ai.md, seção 12).
 * Os dados são gerados em memória, sempre no mês corrente, para "este mês" fazer sentido.
 */
class DemoBusiness
{
    public const SLUG = 'clinica-demo';

    public const ROLES = [
        'dono' => ['financeiro_saldos_em_aberto', 'financeiro_resumo_mes', 'agenda_atendimentos', 'clientes_buscar'],
        'recepcao' => ['agenda_atendimentos', 'clientes_buscar'],
    ];

    public static function profile(): array
    {
        return [
            'name' => 'Clínica Bem Viver (demonstração)',
            'description' => 'Sistema de agenda, clientes e financeiro de uma clínica de estética. Dados fictícios.',
            'tone' => 'Simpático e direto, tratando a pessoa por "você".',
        ];
    }

    public static function knowledge(): string
    {
        return <<<'MD'
        # Manual da Clínica Bem Viver

        O sistema tem quatro menus no topo: Agenda, Clientes, Financeiro e Configurações.

        ## Agenda
        - **Novo atendimento:** Agenda › botão "+ Novo" › escolha cliente, serviço, data e horário › Salvar.
        - **Remarcar:** Agenda › clique no atendimento › Remarcar › escolha o novo horário › Confirmar. O cliente recebe aviso automático por WhatsApp se a opção estiver ligada em Configurações › Avisos.
        - **Cancelar:** Agenda › clique no atendimento › Cancelar › informe o motivo. Atendimentos cancelados continuam visíveis com a etiqueta "Cancelado".
        - **Marcar falta:** Agenda › clique no atendimento › Faltou. Só é possível depois do horário do atendimento.
        - **Ver a semana:** use as setas ao lado da data; o botão "Hoje" volta para o dia atual.

        ## Clientes
        - **Cadastrar:** Clientes › "+ Novo cliente" › nome e telefone são obrigatórios › Salvar.
        - **Editar:** Clientes › clique no nome › Editar.
        - **Histórico:** na ficha do cliente, aba "Atendimentos" mostra todos os atendimentos e a aba "Financeiro" mostra cobranças e pagamentos.

        ## Financeiro
        - **Cobranças em aberto:** Financeiro › Recebimentos › filtro "Em aberto".
        - **Registrar pagamento:** Financeiro › Recebimentos › clique na cobrança › Registrar pagamento › informe forma (Pix, cartão, dinheiro) e data.
        - **Recibo:** depois de registrar o pagamento, botão "Recibo" gera o PDF.
        - **Resumo do mês:** Financeiro › Resumo mostra recebido, a receber e atendimentos realizados.
        - Apenas o perfil "Dono" vê o Financeiro. O perfil "Recepção" vê Agenda e Clientes.

        ## Configurações
        - **Horário de funcionamento:** Configurações › Horários.
        - **Avisos por WhatsApp:** Configurações › Avisos › ligue "Avisar ao remarcar" e "Lembrete 1 dia antes".

        ## Erros comuns
        - **AG-409 "Horário indisponível":** já existe atendimento nesse horário para o mesmo profissional, ou o horário está fora do funcionamento. Escolha outro horário ou confira Configurações › Horários.
        - **FN-422 "Valor inválido":** o valor do pagamento é zero ou maior que o valor em aberto da cobrança.
        - **CL-409 "Cliente já cadastrado":** já existe cliente com o mesmo telefone. Busque pelo telefone em Clientes.
        - **Erro 500 "Algo deu errado":** é falha do sistema, não de uso. Encaminhe ao suporte com o horário em que aconteceu.
        MD;
    }

    public static function tools(): array
    {
        $period = ['type' => 'string', 'description' => 'Mês no formato AAAA-MM'];
        $date = ['type' => 'string', 'description' => 'Data no formato AAAA-MM-DD'];

        return [
            [
                'name' => 'financeiro_saldos_em_aberto',
                'description' => 'Lista clientes com valores em aberto (cobranças não pagas) com vencimento no período.',
                'risk' => 'read',
                'permission' => 'financeiro.ver',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'periodo' => $period,
                    'ordenar' => ['type' => 'string', 'enum' => ['maior_valor', 'mais_antigo']],
                    'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                ], 'required' => ['periodo']],
            ],
            [
                'name' => 'financeiro_resumo_mes',
                'description' => 'Resumo financeiro do mês: total recebido, total a receber e atendimentos realizados.',
                'risk' => 'read',
                'permission' => 'financeiro.ver',
                'input_schema' => ['type' => 'object', 'properties' => ['periodo' => $period], 'required' => ['periodo']],
            ],
            [
                'name' => 'agenda_atendimentos',
                'description' => 'Lista atendimentos entre duas datas (inclusive), opcionalmente filtrando por status.',
                'risk' => 'read',
                'permission' => 'agenda.ver',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'inicio' => $date,
                    'fim' => $date,
                    'status' => ['type' => 'string', 'enum' => ['agendado', 'realizado', 'cancelado', 'faltou']],
                ], 'required' => ['inicio', 'fim']],
            ],
            [
                'name' => 'clientes_buscar',
                'description' => 'Busca clientes pelo nome e mostra último e próximo atendimento e saldo em aberto.',
                'risk' => 'read',
                'permission' => 'clientes.ver',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'termo' => ['type' => 'string', 'maxLength' => 60],
                ], 'required' => ['termo']],
            ],
        ];
    }

    /**
     * Perguntas fixas para avaliar respostas a cada mudança de prompt ou modelo (docs/05-arquitetura.md, seção 9).
     */
    public static function evaluationQuestions(): array
    {
        return [
            'Como eu remarco um atendimento?',
            'Quem mais me deve este mês?',
            'Quanto recebi este mês?',
            'Quais atendimentos tenho amanhã?',
            'Apareceu "Horário indisponível" quando tentei salvar, o que faço?',
            'Como emito nota fiscal?',
            'A Ana Souza tem algo em aberto?',
            'Escreve um lembrete de WhatsApp para quem tem atendimento amanhã',
            'Me dá uma mensagem gentil de cobrança para quem mais deve',
        ];
    }

    public function __construct(private ?CarbonImmutable $today = null)
    {
        $this->today ??= CarbonImmutable::now();
    }

    public function run(string $tool, array $input): mixed
    {
        return match ($tool) {
            'financeiro_saldos_em_aberto' => $this->openBalances($input['periodo'], $input['ordenar'] ?? 'maior_valor', $input['limite'] ?? 10),
            'financeiro_resumo_mes' => $this->monthSummary($input['periodo']),
            'agenda_atendimentos' => $this->appointments($input['inicio'], $input['fim'], $input['status'] ?? null),
            'clientes_buscar' => $this->searchClients($input['termo']),
            default => throw new \InvalidArgumentException("Tool desconhecida: {$tool}"),
        };
    }

    private function appointmentsData(): array
    {
        $month = $this->today->startOfMonth();
        $rows = [
            // [dia, hora, cliente, serviço, valor, pago]
            [2, '09:00', 'Ana Souza', 'Limpeza de pele', 180.00, false],
            [3, '14:00', 'Bruno Lima', 'Massagem relaxante', 150.00, true],
            [6, '10:30', 'Carla Mendes', 'Drenagem linfática', 140.00, true],
            [8, '16:00', 'Ana Souza', 'Peeling', 140.00, false],
            [10, '11:00', 'Diego Rocha', 'Massagem relaxante', 150.00, false],
            [13, '15:00', 'Elisa Prado', 'Limpeza de pele', 180.00, true],
            [15, '09:30', 'Bruno Lima', 'Drenagem linfática', 140.00, false],
            [17, '13:00', 'Carla Mendes', 'Limpeza de pele', 180.00, true],
            [20, '10:00', 'Fábio Nunes', 'Massagem relaxante', 150.00, false],
        ];

        $appointments = [];
        foreach ($rows as [$day, $time, $client, $service, $price, $paid]) {
            $date = $month->setDay(min($day, $month->daysInMonth));
            $appointments[] = ['data' => $date->toDateString(), 'hora' => $time, 'cliente' => $client, 'servico' => $service, 'valor' => $price, 'pago' => $paid,
                'status' => $date->lt($this->today->startOfDay()) ? 'realizado' : 'agendado'];
        }

        // Alguns atendimentos relativos a hoje, para perguntas como "amanhã".
        $appointments[] = ['data' => $this->today->addDay()->toDateString(), 'hora' => '10:00', 'cliente' => 'Elisa Prado', 'servico' => 'Peeling', 'valor' => 140.00, 'pago' => false, 'status' => 'agendado'];
        $appointments[] = ['data' => $this->today->addDay()->toDateString(), 'hora' => '15:30', 'cliente' => 'Diego Rocha', 'servico' => 'Drenagem linfática', 'valor' => 140.00, 'pago' => false, 'status' => 'agendado'];
        $appointments[] = ['data' => $this->today->subDays(2)->toDateString(), 'hora' => '17:00', 'cliente' => 'Gabriela Torres', 'servico' => 'Massagem relaxante', 'valor' => 150.00, 'pago' => false, 'status' => 'faltou'];

        usort($appointments, fn ($a, $b) => [$a['data'], $a['hora']] <=> [$b['data'], $b['hora']]);

        return $appointments;
    }

    private function inPeriod(array $appointment, string $period): bool
    {
        return str_starts_with($appointment['data'], $period);
    }

    private function openBalances(string $period, string $order, int $limit): array
    {
        $byClient = [];
        foreach ($this->appointmentsData() as $a) {
            if ($a['status'] !== 'realizado' || $a['pago'] || ! $this->inPeriod($a, $period)) {
                continue;
            }

            $byClient[$a['cliente']] ??= ['cliente' => $a['cliente'], 'em_aberto' => 0.0, 'cobrancas' => 0, 'vencimento_mais_antigo' => $a['data']];
            $byClient[$a['cliente']]['em_aberto'] += $a['valor'];
            $byClient[$a['cliente']]['cobrancas']++;
            $byClient[$a['cliente']]['vencimento_mais_antigo'] = min($byClient[$a['cliente']]['vencimento_mais_antigo'], $a['data']);
        }

        $list = array_values($byClient);
        usort($list, $order === 'mais_antigo'
            ? fn ($a, $b) => $a['vencimento_mais_antigo'] <=> $b['vencimento_mais_antigo']
            : fn ($a, $b) => $b['em_aberto'] <=> $a['em_aberto']);

        return array_slice($list, 0, $limit);
    }

    private function monthSummary(string $period): array
    {
        $done = array_filter($this->appointmentsData(), fn ($a) => $a['status'] === 'realizado' && $this->inPeriod($a, $period));

        return [
            'periodo' => $period,
            'recebido' => array_sum(array_map(fn ($a) => $a['pago'] ? $a['valor'] : 0, $done)),
            'a_receber' => array_sum(array_map(fn ($a) => $a['pago'] ? 0 : $a['valor'], $done)),
            'atendimentos_realizados' => count($done),
        ];
    }

    private function appointments(string $start, string $end, ?string $status): array
    {
        return array_values(array_map(
            fn ($a) => array_intersect_key($a, array_flip(['data', 'hora', 'cliente', 'servico', 'status'])),
            array_filter($this->appointmentsData(), fn ($a) => $a['data'] >= $start && $a['data'] <= $end && ($status === null || $a['status'] === $status)),
        ));
    }

    private function searchClients(string $term): array
    {
        $term = mb_strtolower(trim($term));
        $clients = [];

        foreach ($this->appointmentsData() as $a) {
            if ($term === '' || ! str_contains(mb_strtolower($a['cliente']), $term)) {
                continue;
            }

            $c = $clients[$a['cliente']] ??= ['nome' => $a['cliente'], 'ultimo_atendimento' => null, 'proximo_atendimento' => null, 'saldo_em_aberto' => 0.0];

            if ($a['status'] === 'realizado') {
                $c['ultimo_atendimento'] = max($c['ultimo_atendimento'] ?? '', $a['data']);
                $c['saldo_em_aberto'] += $a['pago'] ? 0 : $a['valor'];
            } elseif ($a['status'] === 'agendado') {
                $c['proximo_atendimento'] = $c['proximo_atendimento'] ? min($c['proximo_atendimento'], $a['data']) : $a['data'];
            }

            $clients[$a['cliente']] = $c;
        }

        return array_values($clients);
    }
}
