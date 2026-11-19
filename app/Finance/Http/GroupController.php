<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Debts\Contact;
use App\Finance\Groups\Group;
use App\Finance\Groups\GroupExpense;
use App\Finance\Groups\GroupLedger;
use App\Finance\Groups\GroupMember;
use App\Finance\Groups\GroupPayment;
use App\Finance\Insights\Export;
use App\Platform\Context\ContextStore;
use App\Platform\Context\ExecutionContext;
use App\Platform\Organizations\Organization;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Despesas em grupo (fatia 6d): viagem, república, festa. Quanto cada um gastou e pagou, quem paga quem, link
 * só de leitura para o grupo e, no fim, "Levar para Quem me deve".
 */
class GroupController
{
    public function __construct(private GroupLedger $ledger) {}

    public function index(Request $request): View
    {
        $groups = Group::query()->orderByRaw('closed_at is not null')->latest()->get();

        return view('finance.groups.index', [
            'groups' => $groups->map(function (Group $g) {
                $balances = $this->ledger->balances($g);

                return ['group' => $g, 'total' => $balances->sum('share'), 'me' => $balances->first(fn ($b) => $b['member']->is_me), 'people' => $balances->count()];
            }),
            'contacts' => Contact::query()->whereNull('archived_at')->orderBy('name')->get(),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'names' => ['nullable', 'string', 'max:1000'],
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['string', 'max:30'],
        ], [], ['name' => 'nome do grupo']);
        // Nomes separados por vírgula ou um por linha.
        $names = preg_split('/[,\n]+/', (string) ($data['names'] ?? '')) ?: [];
        $group = $this->ledger->create($data['name'], $names, $data['contact_ids'] ?? []);

        return redirect()->route('finance.groups.show', $group)->with('status', "Grupo \"{$group->name}\" criado. Agora é só lançar as despesas.");
    }

    public function show(Request $request, Group $group): View|Response
    {
        $balances = $this->ledger->balances($group);
        $settlements = $this->ledger->settlements($balances);
        $expenses = $group->expenses()->with(['payer', 'shares'])->orderByDesc('date')->orderByDesc('created_at')->get();
        $payments = $group->payments()->with(['from', 'to'])->orderByDesc('date')->get();
        $members = $group->members()->get();

        if ($format = Export::requested($request)) {
            return (new Export('Grupo '.$group->name))
                ->section('Quanto cada um gastou e pagou', ['Pessoa' => 'text', 'Gastou (a parte dela)' => 'money', 'Pagou' => 'money', 'Passou' => 'money', 'Recebeu' => 'money', 'Recebe (+) / deve (−)' => 'money'],
                    $balances->map(fn ($b) => [$b['member']->label(), $b['share'], $b['paid'], $b['sent'], $b['received'], $b['net']]))
                ->section('Quem paga quem', ['De' => 'text', 'Para' => 'text', 'Valor' => 'money'], array_map(fn ($s) => [$s['from']->label(), $s['to']->label(), $s['amount']], $settlements))
                ->section('Despesas', ['Data' => 'date', 'O quê' => 'text', 'Quem pagou' => 'text', 'Valor' => 'money', 'Dividido entre' => 'text'],
                    $expenses->map(fn ($e) => [$e->date, $e->description, $e->payer?->label(), $e->amount_cents,
                        $e->shares->map(fn ($s) => $members->firstWhere('id', $s->member_id)?->label().' '.Money::format($s->amount_cents))->join(', ')]))
                ->download($format);
        }

        $link = route('finance.groups.public', $group->token);

        return view('finance.groups.show', [
            'group' => $group,
            'members' => $members,
            'balances' => $balances,
            'settlements' => $settlements,
            'expenses' => $expenses,
            'payments' => $payments,
            'contacts' => Contact::query()->whereNull('archived_at')->orderBy('name')->get(),
            'link' => $link,
            'whatsapp' => 'https://wa.me/?text='.rawurlencode($this->ledger->summaryText($group, $link, $request->user()->name)),
            'canManage' => $this->canManage($request),
        ]);
    }

    public function addMember(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate(['contact_id' => ['nullable', 'string', 'max:30'], 'name' => ['nullable', 'string', 'max:80']]);
        $member = $this->ledger->addMember($group, $data['contact_id'] ?? null, $data['name'] ?? null);

        return back()->with('status', "{$member->name} está no grupo.");
    }

    public function removeMember(GroupMember $member): RedirectResponse
    {
        $this->ledger->removeMember($member);

        return back()->with('status', "{$member->name} saiu do grupo.");
    }

    public function addExpense(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
            'paid_by' => ['required', 'string', 'max:30'],
            'mode' => ['required', 'in:igual,valores'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['string', 'max:30'],
            'amounts' => ['nullable', 'array'],
            'amounts.*' => ['nullable', 'string', 'max:20'],
        ], [], ['description' => 'o que foi', 'amount' => 'valor', 'paid_by' => 'quem pagou']);
        $amounts = array_map(fn ($v) => Money::toCents($v) ?? 0, $data['amounts'] ?? []);
        $expense = $this->ledger->addExpense($group, $data['description'], Money::toCents($data['amount']) ?? 0, $data['date'], $data['paid_by'], $data['mode'], $data['participants'] ?? [], $amounts);

        return back()->with('status', "Despesa lançada: {$expense->description} · ".Money::format($expense->amount_cents).'.');
    }

    public function destroyExpense(GroupExpense $expense): RedirectResponse
    {
        $expense->delete();

        return back()->with('status', 'Despesa apagada.');
    }

    public function addPayment(Request $request, Group $group): RedirectResponse
    {
        $data = $request->validate(['from' => ['required', 'string', 'max:30'], 'to' => ['required', 'string', 'max:30'], 'amount' => ['required', 'string', 'max:20'], 'date' => ['required', 'date']]);
        $payment = $this->ledger->addPayment($group, $data['from'], $data['to'], Money::toCents($data['amount']) ?? 0, $data['date']);

        return back()->with('status', 'Pagamento registrado: '.$payment->from->label().' → '.$payment->to->label().' · '.Money::format($payment->amount_cents).'.');
    }

    public function destroyPayment(GroupPayment $payment): RedirectResponse
    {
        $payment->delete();

        return back()->with('status', 'Pagamento apagado.');
    }

    public function close(Request $request, Group $group): RedirectResponse
    {
        $close = $request->boolean('close');
        $group->update(['closed_at' => $close ? now() : null]);

        return back()->with('status', $close ? 'Grupo encerrado: as contas ficam como estão.' : 'Grupo reaberto.');
    }

    public function sendToDebts(Group $group): RedirectResponse
    {
        $count = $this->ledger->sendToDebts($group);

        return back()->with('status', $count ? "{$count} acerto(s) seus foram para \"Quem me deve\". O grupo foi encerrado." : 'Nada seu para acertar: o grupo foi encerrado.');
    }

    public function newLink(Group $group): RedirectResponse
    {
        $group->update(['token' => Str::random(40)]);

        return back()->with('status', 'Link novo criado. O antigo parou de funcionar.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $group->delete();

        return redirect()->route('finance.groups')->with('status', "Grupo \"{$group->name}\" apagado.");
    }

    /** Resumo só de leitura para o grupo (sem login; o token secreto é a chave). */
    public function public(ContextStore $contexts, string $token): Response
    {
        $group = Group::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $organization = Organization::findOrFail($group->organization_id);

        // Monta a página dentro do contexto da carteira do grupo (fora dele, o escopo não devolve nada).
        return $contexts->run(ExecutionContext::forSystem($organization, 'public'), function () use ($group) {
            $balances = $this->ledger->balances($group);

            return response(view('finance.groups.public', [
                'group' => $group,
                'balances' => $balances,
                'settlements' => $this->ledger->settlements($balances),
                'expenses' => $group->expenses()->with('payer')->orderByDesc('date')->get(),
            ])->render())->header('X-Robots-Tag', 'noindex, nofollow');
        });
    }

    private function canManage(Request $request): bool
    {
        return Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage');
    }
}
