<?php

namespace App\Finance\Assistant;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Accounts\CardInvoices;
use App\Finance\Categories\Category;
use App\Finance\Debts\Contact;
use App\Finance\Debts\PersonEntry;
use App\Finance\Goals\Goal;
use App\Finance\Groups\Group;
use App\Finance\Groups\GroupLedger;
use App\Finance\Insights\Indicators;
use App\Finance\Partners\PartnerService;
use App\Finance\Transactions\Transaction;
use App\Finance\Wallets\Wallet;
use App\Platform\Memberships\Membership;
use App\Platform\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * O Foxy Finanças como anfitrião da Foxy AI (docs/12 §14.3): perfil, manual e consultas só de leitura. As
 * consultas rodam aqui, na carteira de quem pergunta e com as permissões dela. A IA nunca toca no banco; os
 * números saem prontos (formatados) daqui.
 */
class FinanceHost
{
    public const SLUG = 'foxy-financas';

    /** Consulta → permissão exigida. */
    public const TOOLS = [
        'resumo_periodo' => 'finance.reports.view',
        'gastos_por' => 'finance.reports.view',
        'comparar_periodos' => 'finance.reports.view',
        'economizar' => 'finance.reports.view',
        'assinaturas' => 'finance.reports.view',
        'faturas_cartao' => 'finance.view',
        'orcamento' => 'finance.view',
        'metas' => 'finance.view',
        'quem_me_deve' => 'finance.view',
        'grupos' => 'finance.view',
        'socios_mes' => 'finance.view',
    ];

    public const TOOL_LABELS = [
        'resumo_periodo' => 'Lançamentos', 'gastos_por' => 'Lançamentos', 'comparar_periodos' => 'Lançamentos', 'economizar' => 'Onde vai o dinheiro',
        'assinaturas' => 'Onde vai o dinheiro', 'faturas_cartao' => 'Faturas', 'orcamento' => 'Orçamento', 'metas' => 'Metas',
        'quem_me_deve' => 'Quem me deve', 'grupos' => 'Despesas em grupo', 'socios_mes' => 'Sócios',
    ];

    /** @return string[] consultas que esta pessoa pode usar nesta carteira */
    public static function toolsFor(?Membership $membership, Wallet $wallet): array
    {
        return array_keys(array_filter(self::TOOLS, fn (string $permission, string $tool) => Access::allows($membership, $permission)
            && ($tool !== 'socios_mes' || $wallet->isBusiness()), ARRAY_FILTER_USE_BOTH));
    }

    public static function profile(): array
    {
        return [
            'name' => 'Foxy Finanças',
            'description' => 'Controle de dinheiro pessoal e de MEI/pequeno negócio: contas e cartões, lançamentos, extrato importado, categorias automáticas, faturas, onde vai o dinheiro, orçamento, metas de guardar, conquistas e desafios do mês, quem me deve, despesas em grupo e sócios.',
            'tone' => 'Gentil e direto, tratando a pessoa por "você", sem julgar os gastos. Usa os números exatamente como vêm das consultas e sugere passos pequenos e possíveis.',
        ];
    }

    public static function knowledge(): string
    {
        return <<<'MD'
        # Manual do Foxy Finanças

        O menu fica à esquerda. Cada **carteira** (pessoal/casa ou do negócio) tem as próprias contas e lançamentos; troca-se de carteira no menu. Toda lista tem os botões Planilha e PDF no alto. A Foxy **nunca mexe em dinheiro de verdade**: não paga, não cobra e não pede senha de banco.

        ## Lançar
        - **Lançamentos** › escolher Gasto, Recebimento ou Transferência › valor › descrição. Conta, data e categoria já vêm preenchidas (a categoria é sugerida sozinha).
        - **Parcelado** divide o valor (no cartão, uma parcela em cada fatura). **Todo mês** repete o valor (aluguel, salário). Data futura fica como **previsto** até clicar em "Paguei"/"Recebi".
        - **Editar** um lançamento: lista › Editar. Dá para mudar "este e os próximos" de uma série.
        - **Pagar a fatura do cartão** é uma transferência da conta para o cartão (ou "Faturas do cartão" › Pagar esta fatura).

        ## Importar extrato
        - **Importar extrato** › conta › arquivo baixado no app do banco. OFX entra direto; planilha e PDF pedem as colunas uma vez.
        - Cada linha: **Lançar** (categoria sugerida), **"Já está lançado? — conciliar"** (não duplica o que já foi digitado), **É transferência**, **Ignorar**, **Dividir em partes**, ou lançar várias em lote. **"Sugerir com a raposa"** sugere categorias para o que ficou sem.
        - "Sempre que aparecer" cria uma regra: da próxima vez a categoria vem sozinha. Regras ficam em **Categorias**.

        ## Ver e economizar
        - **Onde vai o dinheiro:** quanto entrou, saiu, quanto da renda guardou, "no ritmo atual o mês fecha em", o que mais subiu, gastinhos (compras até R$ 30), assinaturas, reserva de emergência (meses cobertos pela poupança) e "se cortar pela metade".
        - **Orçamento:** limite por categoria no mês, com aviso a partir de 80%.
        - **Metas de guardar:** valor e prazo; mostra quanto guardar por mês. "Guardei"/"Tirei" na própria meta.
        - **Conquistas:** pontos da carteira (de todos juntos) por mês no azul (no negócio, com lucro) 100, meta no ritmo 50, orçamento respeitado 30, fatura paga até o vencimento 30, extrato do mês revisado 20 e desafio vencido 80; os do mês entram quando ele fecha. Níveis: Filhote, Raposinha (200), Raposa esperta (600), Raposa sábia (1500), Raposa lendária (3000). Até 3 desafios por mês, sugeridos com os números da carteira (gastar menos numa categoria, guardar na meta, poucos gastinhos); aceitar e desistir na tela Conquistas. Lembrete dos desafios no domingo pelo WhatsApp, para quem ligar em Meu perfil. Abrir o app não vale ponto.
        - **Faturas do cartão:** fatura aberta, as a pagar, as futuras com parcelas e o limite disponível.
        - **Calendário:** o mês dia a dia — contas e recebimentos (feitos e previstos) e o dia em que cada fatura vence, com o que falta pagar e receber.

        ## Pessoas
        - **Quem me deve:** emprestei / peguei emprestado / me devolveu / paguei; dividir uma conta (o seu gasto fica só com a sua parte); objetos emprestados; lembrete pelo WhatsApp (abre o WhatsApp da pessoa com o texto pronto).
        - **Despesas em grupo:** viagem, república: cada despesa com quem pagou e entre quem divide; mostra quem paga quem e tem link para o grupo ver.
        - **Pessoas na carteira:** convidar o parceiro, a família ou o sócio (Dono, Membro, Só vê).
        - **Sócios** (carteira do negócio): parte de cada um, pró-labore/retirada/aporte, lucro do mês, quanto cabe a cada um e o acerto; MEI: faturamento no ano contra o limite de R$ 81 mil, lembrete do DAS e a **declaração anual** (DASN-SIMEI, até 31 de maio): o total recebido no ano, mês a mês e por categoria, com planilha e PDF.

        ## WhatsApp e privacidade
        - **Meu perfil:** ligar o WhatsApp, escolher a carteira padrão, o lembrete dos desafios e o **resumo da semana** (domingo de manhã: entrou, saiu, onde mais gastou e o que vence nos próximos 7 dias).
        - **Ajustes da carteira** (dono): **Exportar tudo** (planilha ou PDF com tudo o que a carteira guarda) e **Apagar esta carteira** (pede a senha; apaga para todas as pessoas, não tem volta).
        - **Meu perfil › Apagar minha conta** (pede a senha): apaga as carteiras de que a pessoa é a única dona e a tira das outras.
        MD;
    }

    public static function tools(): array
    {
        $date = ['type' => 'string', 'description' => 'Data no formato AAAA-MM-DD'];
        $month = ['type' => 'string', 'description' => 'Mês no formato AAAA-MM (padrão: o mês atual)'];
        $empty = ['type' => 'object', 'properties' => (object) []];

        return [
            ['name' => 'resumo_periodo', 'risk' => 'read', 'permission' => 'finance.reports.view',
                'description' => 'Quanto entrou, saiu e sobrou entre duas datas, com os 10 grupos de categoria onde mais saiu dinheiro. Não conta transferências nem movimentos de sócio.',
                'input_schema' => ['type' => 'object', 'properties' => ['inicio' => $date, 'fim' => $date], 'required' => ['inicio', 'fim']]],
            ['name' => 'gastos_por', 'risk' => 'read', 'permission' => 'finance.reports.view',
                'description' => 'Gastos entre duas datas agrupados por categoria, por lugar (loja) ou por conta, do maior para o menor. Com "busca", só os lançamentos cuja descrição ou categoria tem esse texto (ex.: delivery, uber).',
                'input_schema' => ['type' => 'object', 'properties' => ['agrupar' => ['type' => 'string', 'enum' => ['categoria', 'lugar', 'conta']], 'inicio' => $date, 'fim' => $date, 'busca' => ['type' => 'string', 'maxLength' => 60], 'limite' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30]], 'required' => ['agrupar', 'inicio', 'fim']]],
            ['name' => 'comparar_periodos', 'risk' => 'read', 'permission' => 'finance.reports.view',
                'description' => 'Compara dois períodos: entrou, saiu e o que mudou em cada grupo de categoria.',
                'input_schema' => ['type' => 'object', 'properties' => ['a_inicio' => $date, 'a_fim' => $date, 'b_inicio' => $date, 'b_fim' => $date], 'required' => ['a_inicio', 'a_fim', 'b_inicio', 'b_fim']]],
            ['name' => 'economizar', 'risk' => 'read', 'permission' => 'finance.reports.view',
                'description' => 'Indicadores do mês para economizar: % da renda guardado, ritmo do mês, o que mais subiu, gastinhos, reserva de emergência e "se cortar pela metade".',
                'input_schema' => ['type' => 'object', 'properties' => ['mes' => $month]]],
            ['name' => 'assinaturas', 'risk' => 'read', 'permission' => 'finance.reports.view',
                'description' => 'Assinaturas e cobranças que se repetem todo mês (fora o essencial), com o custo por mês e por ano.',
                'input_schema' => $empty],
            ['name' => 'faturas_cartao', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Faturas de cada cartão: aberta, a pagar e futuras (com parcelas), vencimento, situação e limite disponível.',
                'input_schema' => $empty],
            ['name' => 'orcamento', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Limites de gasto por categoria no mês: limite, quanto já foi e a situação.',
                'input_schema' => ['type' => 'object', 'properties' => ['mes' => $month]]],
            ['name' => 'metas', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Metas de guardar dinheiro: quanto juntou, quanto falta, prazo e quanto guardar por mês.',
                'input_schema' => $empty],
            ['name' => 'quem_me_deve', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Quem deve à pessoa e a quem ela deve (amigos, família), com o saldo de cada um, e os objetos emprestados que não voltaram.',
                'input_schema' => $empty],
            ['name' => 'grupos', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Grupos de despesas (viagem, república): total, a parte da pessoa e quem paga quem.',
                'input_schema' => $empty],
            ['name' => 'socios_mes', 'risk' => 'read', 'permission' => 'finance.view',
                'description' => 'Carteira do negócio: lucro do mês, a parte de cada sócio, quanto cada um tirou e o acerto.',
                'input_schema' => ['type' => 'object', 'properties' => ['mes' => $month]]],
        ];
    }

    public function run(string $tool, array $input, array $allowed): mixed
    {
        if (! in_array($tool, $allowed, true)) {
            throw ValidationException::withMessages(['tool' => 'Você não tem acesso a essa informação.']);
        }

        return match ($tool) {
            'resumo_periodo' => $this->summary(...$this->range($input['inicio'] ?? null, $input['fim'] ?? null)),
            'gastos_por' => $this->spendingBy((string) ($input['agrupar'] ?? 'categoria'), $this->range($input['inicio'] ?? null, $input['fim'] ?? null), $input['busca'] ?? null, (int) ($input['limite'] ?? 10)),
            'comparar_periodos' => $this->compare($this->range($input['a_inicio'] ?? null, $input['a_fim'] ?? null), $this->range($input['b_inicio'] ?? null, $input['b_fim'] ?? null)),
            'economizar' => $this->saving($this->month($input['mes'] ?? null)),
            'assinaturas' => Indicators::for(today())->subscriptions()->map(fn ($s) => ['o_que' => $s['label'], 'categoria' => $s['category'], 'por_mes' => self::money($s['monthly']), 'por_ano' => self::money($s['yearly'])])->all(),
            'faturas_cartao' => $this->cards(),
            'orcamento' => Indicators::for($this->month($input['mes'] ?? null))->budgets()->map(fn ($b) => ['categoria' => $b['category']->fullName(), 'limite' => self::money($b['limit']), 'gasto' => self::money($b['spent']), 'usado' => round($b['pct'] * 100).'%', 'situacao' => $b['status']])->all(),
            'metas' => Goal::query()->whereNull('archived_at')->withSum('entries', 'amount_cents')->get()->map(function (Goal $g) {
                $p = $g->progress();

                return ['meta' => $g->name, 'quer_juntar' => self::money($g->target_cents), 'juntou' => self::money($p['saved']), 'falta' => self::money($p['missing']),
                    'prazo' => $g->deadline?->format('d/m/Y'), 'guardar_por_mes' => $p['per_month'] ? self::money($p['per_month']) : null, 'no_ritmo' => $p['on_track'], 'alcancada' => $p['done']];
            })->all(),
            'quem_me_deve' => $this->debts(),
            'grupos' => $this->groups(),
            'socios_mes' => $this->partners($this->month($input['mes'] ?? null)),
        };
    }

    private function summary(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to);
        $income = $rows->where('kind', Transaction::INCOME)->sum('amount_cents');
        $expense = $rows->where('kind', Transaction::EXPENSE)->sum('amount_cents');

        return [
            'periodo' => $from->format('d/m/Y').' a '.$to->format('d/m/Y'),
            'entrou' => self::money($income), 'saiu' => self::money($expense), 'sobrou' => self::money($income - $expense),
            'onde_mais_saiu' => $this->byGroup($rows)->take(10)->map(fn ($v, $k) => ['categoria' => $k, 'total' => self::money($v)])->values()->all(),
        ];
    }

    private function spendingBy(string $by, array $range, ?string $search, int $limit): array
    {
        [$from, $to] = $range;
        $rows = $this->rows($from, $to)->where('kind', Transaction::EXPENSE);
        $categories = Category::with('parent')->get()->keyBy('id');
        if ($search) {
            $needle = Str::lower(Str::ascii($search));
            $rows = $rows->filter(fn ($t) => str_contains(Str::lower(Str::ascii($t->description.' '.($categories->get($t->category_id)?->fullName() ?? ''))), $needle));
        }
        $accounts = Account::query()->pluck('name', 'id');
        $groups = match ($by) {
            'lugar' => $rows->groupBy(fn ($t) => \App\Finance\Categories\Categorizer::defaultPattern($t->description)),
            'conta' => $rows->groupBy(fn ($t) => $accounts[$t->account_id] ?? 'Conta'),
            default => $rows->groupBy(fn ($t) => $categories->get($t->category_id)?->fullName() ?? 'Sem categoria'),
        };

        return [
            'periodo' => $from->format('d/m/Y').' a '.$to->format('d/m/Y'),
            'total' => self::money($rows->sum('amount_cents')),
            'vezes' => $rows->count(),
            'grupos' => $groups->map(fn ($g, $k) => ['nome' => $by === 'lugar' ? Str::limit($g->first()->description, 40) : $k, 'total' => self::money($g->sum('amount_cents')), 'vezes' => $g->count(), '_c' => $g->sum('amount_cents')])
                ->sortByDesc('_c')->take(max(1, min(30, $limit)))->map(fn ($g) => array_diff_key($g, ['_c' => 1]))->values()->all(),
        ];
    }

    private function compare(array $a, array $b): array
    {
        $ra = $this->rows(...$a);
        $rb = $this->rows(...$b);
        $ga = $this->byGroup($ra);
        $gb = $this->byGroup($rb);
        $names = $ga->keys()->merge($gb->keys())->unique();

        return [
            'periodo_a' => ['de' => $a[0]->format('d/m/Y'), 'ate' => $a[1]->format('d/m/Y'), 'entrou' => self::money($ra->where('kind', 'recebimento')->sum('amount_cents')), 'saiu' => self::money($ra->where('kind', 'gasto')->sum('amount_cents'))],
            'periodo_b' => ['de' => $b[0]->format('d/m/Y'), 'ate' => $b[1]->format('d/m/Y'), 'entrou' => self::money($rb->where('kind', 'recebimento')->sum('amount_cents')), 'saiu' => self::money($rb->where('kind', 'gasto')->sum('amount_cents'))],
            'por_categoria' => $names->map(fn ($n) => ['categoria' => $n, 'a' => self::money($ga[$n] ?? 0), 'b' => self::money($gb[$n] ?? 0), 'diferenca' => self::money(($gb[$n] ?? 0) - ($ga[$n] ?? 0)), '_d' => abs(($gb[$n] ?? 0) - ($ga[$n] ?? 0))])
                ->sortByDesc('_d')->take(12)->map(fn ($r) => array_diff_key($r, ['_d' => 1]))->values()->all(),
        ];
    }

    private function saving(Carbon $month): array
    {
        $i = Indicators::for($month);
        $s = $i->summary();
        $pace = $i->pace();
        $small = $i->smallSpending();
        $reserve = $i->reserve();
        $cut = $i->cutInHalf();

        return [
            'mes' => $month->format('m/Y'),
            'entrou' => self::money($s['income']), 'saiu' => self::money($s['expense']),
            'guardou_da_renda' => $s['savings_rate'] === null ? null : round($s['savings_rate'] * 100).'%',
            'media_3_meses_antes' => $s['avg_savings_rate'] === null ? null : round($s['avg_savings_rate'] * 100).'%',
            'ritmo' => $pace ? ['fecha_em' => self::money($pace['projected']), 'por_dia' => self::money($pace['per_day']), 'ja_previsto' => self::money($pace['planned'])] : null,
            'mais_subiu' => $i->rises()->map(fn ($r) => ['categoria' => $r['name'], 'antes' => self::money($r['avg']), 'agora' => self::money($r['total']), 'subiu' => self::money($r['delta'])])->all(),
            'gastinhos' => ['no_mes' => self::money($small['total']), 'compras' => $small['count'], 'por_ano' => self::money($small['per_year'])],
            'reserva' => $reserve['has_savings_account'] ? ['guardado' => self::money($reserve['saved']), 'meses' => $reserve['months'] === null ? null : round($reserve['months'], 1)] : 'sem conta poupança cadastrada',
            'cortar_pela_metade' => ['categorias' => $cut['categories']->pluck('name')->all(), 'guardaria_por_ano' => self::money($cut['per_year'])],
        ];
    }

    private function cards(): array
    {
        $invoices = app(CardInvoices::class);

        return Account::query()->where('kind', Account::CARD)->whereNull('archived_at')->get()->map(function (Account $card) use ($invoices) {
            $outstanding = $invoices->outstanding($card);

            return [
                'cartao' => $card->name, 'falta_pagar' => self::money($outstanding),
                'limite_disponivel' => $card->limit_cents !== null ? self::money($card->limit_cents - $outstanding) : null,
                'faturas' => $invoices->overview($card)->map(fn ($i) => ['fatura' => Carbon::createFromFormat('Y-m-d', $i['month'].'-01')->format('m/Y'), 'vence' => $i['due']->format('d/m/Y'), 'gastos' => self::money($i['charges']), 'falta' => self::money($i['remaining']), 'situacao' => CardInvoices::STATUSES[$i['status']]])->all(),
            ];
        })->all();
    }

    private function debts(): array
    {
        $contacts = Contact::query()->withSum('entries', 'amount_cents')->get()->filter(fn ($c) => $c->balanceCents() !== 0);

        return [
            'te_devem' => $contacts->filter(fn ($c) => $c->balanceCents() > 0)->map(fn ($c) => ['pessoa' => $c->name, 'valor' => self::money($c->balanceCents())])->values()->all(),
            'voce_deve' => $contacts->filter(fn ($c) => $c->balanceCents() < 0)->map(fn ($c) => ['pessoa' => $c->name, 'valor' => self::money(-$c->balanceCents())])->values()->all(),
            'objetos_emprestados' => PersonEntry::query()->with('contact')->where('kind', 'objeto')->whereNull('returned_at')->get()->map(fn ($o) => ['o_que' => $o->description, 'com' => $o->contact?->name, 'desde' => $o->date->format('d/m/Y')])->all(),
        ];
    }

    private function groups(): array
    {
        $ledger = app(GroupLedger::class);

        return Group::query()->latest()->limit(10)->get()->map(function (Group $g) use ($ledger) {
            $balances = $ledger->balances($g);
            $me = $balances->first(fn ($b) => $b['member']->is_me);

            return ['grupo' => $g->name, 'encerrado' => $g->isClosed(), 'total' => self::money($balances->sum('share')), 'sua_parte' => self::money($me['share'] ?? 0),
                'voce' => ($me['net'] ?? 0) > 0 ? 'recebe '.self::money($me['net']) : (($me['net'] ?? 0) < 0 ? 'deve '.self::money(-$me['net']) : 'quite'),
                'quem_paga_quem' => array_map(fn ($s) => $s['from']->label().' paga '.$s['to']->label().': '.self::money($s['amount']), $ledger->settlements($balances))];
        })->all();
    }

    private function partners(Carbon $month): array
    {
        $s = app(PartnerService::class)->summary($month, $month->copy()->endOfMonth()->startOfDay());

        return [
            'mes' => $month->format('m/Y'), 'lucro' => self::money($s['profit']), 'entrou' => self::money($s['income']), 'saiu' => self::money($s['expense']),
            'socios' => $s['partners']->map(fn ($r) => ['socio' => $r['partner']->name, 'parte' => $r['partner']->shareLabel(), 'cabe' => self::money($r['due']), 'tirou' => self::money($r['took']),
                'acerto' => $r['diff'] > 0 ? 'tirou '.self::money($r['diff']).' a mais' : ($r['diff'] < 0 ? 'ainda tem '.self::money(-$r['diff']).' para tirar' : 'em dia')])->all(),
        ];
    }

    /** Lançamentos feitos no período, sem transferências nem movimentos de sócio (os mesmos números das telas). */
    private function rows(Carbon $from, Carbon $to)
    {
        return Transaction::query()->done()->whereNull('partner_id')->whereIn('kind', [Transaction::EXPENSE, Transaction::INCOME])
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get(['kind', 'amount_cents', 'date', 'description', 'category_id', 'account_id']);
    }

    /** Gastos por grupo de categoria (a subcategoria soma no grupo), do maior para o menor. */
    private function byGroup($rows)
    {
        $categories = Category::query()->get()->keyBy('id');

        return $rows->where('kind', Transaction::EXPENSE)->groupBy(function ($t) use ($categories) {
            $c = $categories->get($t->category_id);

            return ($c?->parent_id ? $categories->get($c->parent_id)?->name : $c?->name) ?? 'Sem categoria';
        })->map->sum('amount_cents')->sortDesc();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(?string $from, ?string $to): array
    {
        try {
            $a = $from ? Carbon::parse($from)->startOfDay() : today()->startOfMonth();
            $b = $to ? Carbon::parse($to)->startOfDay() : today();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['data' => 'Datas no formato AAAA-MM-DD.']);
        }

        return $a->lte($b) ? [$a, $b] : [$b, $a];
    }

    private function month(?string $month): Carbon
    {
        return $month && preg_match('/^\d{4}-\d{2}$/', $month) ? Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay() : today()->startOfMonth();
    }

    private static function money(int $cents): string
    {
        return Money::format($cents);
    }
}
