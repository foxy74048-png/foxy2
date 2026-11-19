<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Accounts\Account;
use App\Finance\Categories\Category;
use App\Finance\Debts\Contact;
use App\Finance\Debts\DebtLedger;
use App\Finance\Debts\PersonEntry;
use App\Finance\Insights\Export;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Quem me deve" (fatia 6c): o saldo com cada pessoa, registrar empréstimo e devolução, dividir uma conta,
 * objetos emprestados e o lembrete pelo WhatsApp (abre o WhatsApp da própria pessoa; nada é enviado sozinho).
 */
class DebtController
{
    public function __construct(private DebtLedger $ledger) {}

    public function index(Request $request): View|Response
    {
        $contacts = Contact::query()->withSum('entries', 'amount_cents')->orderBy('name')->get();
        $objects = PersonEntry::query()->with('contact')->where('kind', 'objeto')->whereNull('returned_at')->orderBy('date')->get();

        if ($format = Export::requested($request)) {
            return (new Export('Quem me deve'))
                ->section('Saldo por pessoa', ['Pessoa' => 'text', 'Telefone' => 'text:planilha', 'Te deve' => 'money', 'Você deve' => 'money'],
                    $contacts->filter(fn ($c) => $c->balanceCents() !== 0)->map(fn ($c) => [$c->name, $c->phone, max(0, $c->balanceCents()), max(0, -$c->balanceCents())]))
                ->section('Objetos emprestados', ['Com quem' => 'text', 'O quê' => 'text', 'Desde' => 'date'], $objects->map(fn ($o) => [$o->contact?->name, $o->description, $o->date]))
                ->download($format);
        }

        $owedToMe = $contacts->filter(fn ($c) => $c->balanceCents() > 0)->sortByDesc(fn ($c) => $c->balanceCents())->values();
        $iOwe = $contacts->filter(fn ($c) => $c->balanceCents() < 0)->sortBy(fn ($c) => $c->balanceCents())->values();

        return view('finance.debts.index', [
            'contacts' => $contacts->whereNull('archived_at')->values(),
            'owedToMe' => $owedToMe,
            'iOwe' => $iOwe,
            'settled' => $contacts->filter(fn ($c) => $c->balanceCents() === 0 && $c->archived_at === null)->values(),
            'objects' => $objects,
            'totalOwedToMe' => $owedToMe->sum(fn ($c) => $c->balanceCents()),
            'totalIOwe' => -$iOwe->sum(fn ($c) => $c->balanceCents()),
            'accounts' => Account::active()->orderBy('name')->get(),
            'categoryOptions' => Category::options()['gasto'] ?? [],
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
        ]);
    }

    public function show(Request $request, Contact $contact): View
    {
        $balance = $contact->balanceCents();

        return view('finance.debts.show', [
            'contact' => $contact,
            'balance' => $balance,
            'entries' => $contact->entries()->orderByDesc('date')->orderByDesc('created_at')->get(),
            'reminder' => $balance > 0 ? $contact->whatsappLink("Oi, {$contact->name}! Tudo bem? Passando só para lembrar dos ".Money::format($balance).' que ficaram combinados. Quando der, me avisa. Obrigado!') : null,
            'accounts' => Account::active()->orderBy('name')->get(),
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.transactions.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'kind' => ['required', 'in:emprestei,peguei,devolveu,paguei'],
            'amount' => ['required', 'string', 'max:20'],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'account_id' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:160'],
        ], [], ['amount' => 'valor', 'date' => 'data']);
        $contact = $this->ledger->contact($data['contact_id'] ?? null, $data['contact_name'] ?? null);
        $entry = $this->ledger->record($contact, $data['kind'], Money::toCents($data['amount']) ?? 0, $data['date'], $data['description'] ?? null, $data['account_id'] ?? null, $data['due_date'] ?? null);

        return back()->with('status', $entry->label().' · '.Money::format(abs($entry->amount_cents)).' · '.$contact->name.'. '.$this->balanceText($contact->fresh()));
    }

    public function split(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:160'],
            'total' => ['required', 'string', 'max:20'],
            'my_share' => ['nullable', 'string', 'max:20'],
            'account_id' => ['required', 'string', 'max:30'],
            'category_id' => ['nullable', 'string', 'max:30'],
            'date' => ['required', 'date'],
            'people' => ['required', 'array', 'max:12'],
            'people.*.contact_id' => ['nullable', 'string', 'max:30'],
            'people.*.name' => ['nullable', 'string', 'max:80'],
            'people.*.amount' => ['nullable', 'string', 'max:20'],
        ], [], ['description' => 'o que foi', 'total' => 'total', 'account_id' => 'conta']);

        $shares = [];
        foreach ($data['people'] as $row) {
            $cents = Money::toCents($row['amount'] ?? null) ?? 0;
            if ($cents <= 0 || (empty($row['contact_id']) && trim((string) ($row['name'] ?? '')) === '')) {
                continue;
            }
            $contact = $this->ledger->contact($row['contact_id'] ?? null, $row['name'] ?? null);
            $shares[$contact->id] = ($shares[$contact->id] ?? 0) + $cents;
        }
        $total = Money::toCents($data['total']) ?? 0;
        $my = Money::toCents($data['my_share'] ?? null) ?? max(0, $total - array_sum($shares));
        $count = $this->ledger->split($data['account_id'], $total, $my, $data['category_id'] ?? null, $data['description'], $data['date'], $shares);

        return back()->with('status', "Conta dividida: o seu gasto ficou ".Money::format($my)." e {$count} pessoa(s) passam a te dever a parte delas.");
    }

    public function object(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_id' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'what' => ['required', 'string', 'max:160'],
            'date' => ['required', 'date'],
        ], [], ['what' => 'o que emprestou']);
        $contact = $this->ledger->contact($data['contact_id'] ?? null, $data['contact_name'] ?? null);
        $this->ledger->lendObject($contact, $data['what'], $data['date']);

        return back()->with('status', "Anotado: {$data['what']} com {$contact->name}.");
    }

    public function returnObject(Request $request, PersonEntry $entry): RedirectResponse
    {
        $this->ledger->returnObject($entry, ! $request->boolean('undo'));

        return back()->with('status', $request->boolean('undo') ? 'Voltou para "com a pessoa".' : "Devolvido: {$entry->description}.");
    }

    public function destroyEntry(PersonEntry $entry): RedirectResponse
    {
        $count = $this->ledger->delete($entry);

        return back()->with('status', $count > 1 ? "Divisão apagada ({$count} pessoas) e os lançamentos dela também." : 'Movimento apagado.');
    }

    public function updateContact(Request $request, Contact $contact): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'phone' => ['nullable', 'string', 'max:30']], [], ['name' => 'nome', 'phone' => 'WhatsApp']);
        $contact->update(['name' => trim($data['name']), 'phone' => $data['phone'] ?? null]);

        return back()->with('status', 'Salvo.');
    }

    public function archiveContact(Request $request, Contact $contact): RedirectResponse
    {
        if ($request->boolean('archive') && $contact->balanceCents() !== 0) {
            throw ValidationException::withMessages(['contact' => 'Só dá para tirar da lista quem está com o saldo zerado.']);
        }
        $contact->update(['archived_at' => $request->boolean('archive') ? now() : null]);

        return redirect()->route('finance.debts')->with('status', $request->boolean('archive') ? "{$contact->name} saiu da lista (o histórico continua)." : "{$contact->name} voltou para a lista.");
    }

    private function balanceText(Contact $contact): string
    {
        $b = $contact->balanceCents();

        return match (true) {
            $b > 0 => "{$contact->name} te deve ".Money::format($b).'.',
            $b < 0 => "Você deve ".Money::format(-$b)." a {$contact->name}.",
            default => "Vocês estão quites.",
        };
    }
}
