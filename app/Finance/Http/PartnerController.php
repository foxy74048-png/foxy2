<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Insights\Export;
use App\Finance\Partners\Partner;
use App\Finance\Partners\PartnerService;
use App\Finance\Partners\Settlement;
use App\Finance\Statements\StatementLine;
use App\Finance\Transactions\Transaction;
use App\Finance\Transactions\TransactionFilters;
use App\Platform\Audit\Audit;
use App\Platform\Memberships\Membership;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sócios e MEI (fatia 7), só na carteira do negócio: a parte de cada sócio, pró-labore / retirada /
 * distribuição / aporte, lucro do período e quanto cabe a cada um, acerto entre sócios, retirada na carteira
 * pessoal do sócio, faturamento do MEI no ano e o lembrete do DAS.
 */
class PartnerController
{
    public function __construct(private PartnerService $service) {}

    public function index(Request $request): View|Response
    {
        $wallet = $request->attributes->get('finance.wallet');
        abort_unless($wallet->isBusiness(), 404);
        [$from, $to, $period] = $this->period($request);
        $summary = $this->service->summary($from, $to);

        // Declaração anual do MEI: o ano passado (o que se declara até 31 de maio), ou o escolhido.
        $declaration = null;
        if ($wallet->is_mei) {
            $year = (int) $request->query('declaracao', today()->year - 1);
            $declaration = $this->service->meiDeclaration(max(2000, min(today()->year, $year)));
        }
        if ($declaration && $request->has('declaracao') && ($format = Export::requested($request))) {
            return (new Export('Declaração anual do MEI '.$declaration['year']))
                ->filters(['Ano' => (string) $declaration['year'], 'Prazo' => $declaration['due']->format('d/m/Y')])
                ->section('Total recebido no ano', ['Receita bruta total' => 'money'], [[$declaration['total']]])
                ->section('Mês a mês', ['Mês' => 'text', 'Recebido' => 'money'],
                    collect($declaration['months'])->map(fn ($c, $m) => [TransactionFilters::monthName(Carbon::create($declaration['year'], $m, 1)), $c])->values())
                ->section('Por categoria (para separar venda e serviço)', ['Categoria' => 'text', 'Recebido' => 'money'],
                    collect($declaration['categories'])->map(fn ($c, $name) => [$name, $c])->values())
                ->download($format);
        }

        if ($format = Export::requested($request)) {
            return (new Export('Sócios'))->filters(['Período' => $period['label']])
                ->section('Resumo', ['Entrou' => 'money', 'Saiu (sem sócios)' => 'money', 'Lucro' => 'money'], [[$summary['income'], $summary['expense'], $summary['profit']]])
                ->section('Por sócio', ['Sócio' => 'text', 'Parte (%)' => 'percent', 'Cabe a ele' => 'money', 'Pró-labore' => 'money', 'Retiradas' => 'money', 'Distribuição' => 'money', 'Aportes' => 'money', 'Acertos' => 'money', 'Tirou (líquido)' => 'money', 'Diferença' => 'money'],
                    $summary['partners']->map(fn ($r) => [$r['partner']->name, $r['partner']->share_bp / 100, $r['due'], $r['pro_labore'], $r['retirada'], $r['distribuicao'], $r['aporte'], $r['settled'], $r['took'], $r['diff']]))
                ->download($format);
        }

        $me = $request->user();
        $myPartner = Partner::query()->whereNull('archived_at')->where('user_id', $me->getKey())->first();

        return view('finance.partners', [
            'period' => $period,
            'summary' => $summary,
            'suggested' => $this->service->suggestedSettlements($summary['partners']),
            'partners' => Partner::query()->orderByRaw('archived_at is not null')->orderBy('name')->get(),
            'members' => Membership::with('user')->active()->where('organization_id', $wallet->organization_id)->get(),
            'accounts' => Account::active()->orderBy('name')->get(),
            'moves' => Transaction::query()->whereNotNull('partner_id')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->with('account')->orderByDesc('date')->limit(30)->get(),
            'settlements' => Settlement::query()->with('from', 'to')->whereBetween('date', [$from->toDateString(), $to->toDateString()])->orderByDesc('date')->get(),
            'mei' => $wallet->is_mei ? $this->service->meiYear((int) $from->year) : null,
            'declaration' => $declaration,
            'hasDas' => Transaction::query()->where('description', 'DAS do MEI')->where('date', '>=', today()->toDateString())->exists(),
            'myPartner' => $myPartner,
            'mirrorOptions' => $myPartner ? $this->service->mirrorOptions($me->getKey()) : collect(),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
            'canManagePartners' => Access::allows($request->attributes->get('finance.membership'), 'finance.wallet.manage'),
        ]);
    }

    public function storePartner(Request $request): RedirectResponse
    {
        $this->business($request);
        $data = $this->validatedPartner($request);
        $partner = Partner::create($data);
        Audit::record('finance.partner.created', subject: $partner);

        return back()->with('status', "Sócio {$partner->name} cadastrado com {$partner->shareLabel()}.".$this->sharesWarning());
    }

    public function updatePartner(Request $request, Partner $partner): RedirectResponse
    {
        $this->business($request);
        $partner->update($this->validatedPartner($request, $partner));

        return back()->with('status', 'Sócio salvo.'.$this->sharesWarning());
    }

    public function archivePartner(Request $request, Partner $partner): RedirectResponse
    {
        $this->business($request);
        $partner->update(['archived_at' => $request->boolean('archive') ? now() : null]);

        return back()->with('status', $request->boolean('archive') ? "{$partner->name} saiu da sociedade (o histórico continua)." : "{$partner->name} voltou.");
    }

    public function movement(Request $request): RedirectResponse
    {
        $this->business($request);
        $data = $request->validate([
            'partner_id' => ['required', 'string', 'max:30'],
            'kind' => ['required', 'in:'.implode(',', array_keys(Partner::KINDS))],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
            'account_id' => ['required', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:120'],
        ], [], ['partner_id' => 'sócio', 'amount' => 'valor', 'account_id' => 'conta']);
        $partner = Partner::query()->whereNull('archived_at')->findOrFail($data['partner_id']);
        $account = Account::active()->findOrFail($data['account_id']);

        $t = $this->service->record($partner, $data['kind'], Money::toCents($data['amount']) ?? 0, $data['date'], $account->id, $data['description'] ?? null);

        return back()->with('status', Partner::KINDS[$data['kind']][0].' de '.Money::format($t->amount_cents).' para '.$partner->name.'.'.($t->linked_transaction_id ? ' Entrou também na carteira pessoal dele.' : ''));
    }

    public function settle(Request $request): RedirectResponse
    {
        $this->business($request);
        $data = $request->validate([
            'from_partner_id' => ['required', 'string', 'max:30'],
            'to_partner_id' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);
        $from = Partner::query()->findOrFail($data['from_partner_id']);
        $to = Partner::query()->findOrFail($data['to_partner_id']);
        $this->service->settle($from, $to, Money::toCents($data['amount']) ?? 0, $data['date'], $data['note'] ?? null);

        return back()->with('status', "Acerto anotado: {$from->name} pagou ".Money::format(Money::toCents($data['amount']))." a {$to->name}, por fora do caixa.");
    }

    public function destroySettlement(Request $request, Settlement $settlement): RedirectResponse
    {
        $this->business($request);
        $settlement->delete();

        return back()->with('status', 'Acerto apagado.');
    }

    /** O sócio logado escolhe onde as retiradas dele entram na carteira pessoal (ou desliga). */
    public function mirror(Request $request, Partner $partner): RedirectResponse
    {
        $this->business($request);
        $data = $request->validate(['mirror_account_id' => ['nullable', 'string', 'max:30']]);
        $this->service->setMirror($partner, $request->user()->getKey(), $data['mirror_account_id'] ?? null);

        return back()->with('status', ! empty($data['mirror_account_id']) ? 'Daqui para frente, suas retiradas entram também na sua carteira pessoal.' : 'Suas retiradas não entram mais na sua carteira pessoal.');
    }

    public function das(Request $request): RedirectResponse
    {
        $wallet = $this->business($request);
        abort_unless($wallet->is_mei, 404);
        $data = $request->validate(['account_id' => ['required', 'string', 'max:30'], 'amount' => ['required', 'string', 'max:20']], [], ['amount' => 'valor do DAS']);
        $cents = Money::toCents($data['amount']) ?? 0;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe o valor do DAS (está no app MEI ou no PGMEI).']);
        }
        $count = $this->service->dasReminder($wallet, Account::active()->findOrFail($data['account_id'])->id, $cents);

        return back()->with('status', "Lembrete do DAS criado: {$count} meses previstos no dia ".($wallet->das_day ?: 20).'. Confirme com "Paguei" quando pagar.');
    }

    /** Linha do extrato que foi gasto pessoal de um sócio: vira retirada dele. */
    public function lineWithdrawal(Request $request, StatementLine $line): RedirectResponse
    {
        $this->business($request);
        $data = $request->validate(['partner_id' => ['required', 'string', 'max:30']]);
        $partner = Partner::query()->whereNull('archived_at')->findOrFail($data['partner_id']);
        $this->service->lineAsWithdrawal($line, $partner);

        return back()->with('status', "Lançado como retirada de {$partner->name}: não conta como gasto do negócio.");
    }

    private function business(Request $request)
    {
        $wallet = $request->attributes->get('finance.wallet');
        abort_unless($wallet->isBusiness(), 404);

        return $wallet;
    }

    private function validatedPartner(Request $request, ?Partner $partner = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'share' => ['required', 'numeric', 'min:0', 'max:100'],
            'user_id' => ['nullable', 'integer'],
        ], [], ['name' => 'nome', 'share' => 'parte (%)']);
        $userId = $data['user_id'] ?? null;
        if ($userId && ! Membership::active()->where('organization_id', $request->attributes->get('finance.wallet')->organization_id)->where('user_id', $userId)->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Escolha uma pessoa que está nesta carteira.']);
        }

        return ['name' => trim($data['name']), 'share_bp' => (int) round(((float) str_replace(',', '.', (string) $data['share'])) * 100), 'user_id' => $userId];
    }

    private function sharesWarning(): string
    {
        $total = (int) Partner::query()->whereNull('archived_at')->sum('share_bp');

        return $total === 10000 ? '' : ' Atenção: as partes somam '.rtrim(rtrim(number_format($total / 100, 2, ',', '.'), '0'), ',').'% (o certo é 100%).';
    }

    /** @return array{0: Carbon, 1: Carbon, 2: array} mês (padrão) ou ano */
    private function period(Request $request): array
    {
        if (preg_match('/^\d{4}$/', (string) $request->query('ano'))) {
            $from = Carbon::create((int) $request->query('ano'), 1, 1)->startOfDay();

            return [$from, $from->copy()->endOfYear()->startOfDay(), ['kind' => 'ano', 'value' => $from->year, 'label' => 'Ano de '.$from->year]];
        }
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes')) ? Carbon::createFromFormat('Y-m-d', $request->query('mes').'-01')->startOfDay() : today()->startOfMonth();

        return [$month, $month->copy()->endOfMonth()->startOfDay(), ['kind' => 'mes', 'value' => $month->format('Y-m'), 'label' => $month->format('m/Y'), 'month' => $month]];
    }
}
