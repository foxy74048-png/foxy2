<?php

namespace App\Finance\Privacy;

use App\Finance\Accounts\Account;
use App\Finance\Budgets\Budget;
use App\Finance\Categories\Category;
use App\Finance\Categories\CategoryRule;
use App\Finance\Debts\Contact;
use App\Finance\Debts\PersonEntry;
use App\Finance\Goals\Goal;
use App\Finance\Goals\GoalEntry;
use App\Finance\Groups\GroupExpense;
use App\Finance\Insights\Export;
use App\Finance\Partners\Partner;
use App\Finance\Rewards\Reward;
use App\Finance\Transactions\Transaction;
use App\Finance\Wallets\Wallet;
use App\Platform\Memberships\Membership;
use App\Platform\Organizations\Organization;

/**
 * "Exportar tudo" da carteira (fatia 11, LGPD): uma planilha (ou PDF) com tudo o que a carteira guarda,
 * em seções. Roda no contexto da carteira atual, então as consultas já ficam presas a ela.
 * Lançamentos apagados não entram (não aparecem em lugar nenhum do sistema).
 */
class WalletArchive
{
    public function build(Organization $organization): Export
    {
        $wallet = Wallet::firstOrFail();
        $accounts = Account::query()->orderBy('name')->get();
        $categories = Category::query()->with('parent')->get()->keyBy('id');
        $category = fn (?string $id) => $id ? $categories->get($id)?->fullName() : null;
        $groups = \App\Finance\Groups\Group::query()->get()->keyBy('id');
        $people = Membership::query()->where('organization_id', $organization->getKey())->with('user')->get();

        return (new Export('Tudo da carteira '.$wallet->name))
            ->filters(['Exportado em' => now()->format('d/m/Y H:i')])
            ->section('Carteira', ['Nome' => 'text', 'Tipo' => 'text', 'MEI' => 'text', 'Dia do DAS' => 'int', 'Criada em' => 'date'],
                [[$wallet->name, $wallet->kindLabel(), $wallet->is_mei ? 'Sim' : 'Não', $wallet->das_day, $wallet->created_at]])
            ->section('Pessoas na carteira', ['Nome' => 'text', 'E-mail' => 'text', 'Papel' => 'text', 'Situação' => 'text', 'Desde' => 'date'],
                $people->map(fn ($m) => [$m->user?->name, $m->user?->email, \App\Finance\Access::label($m->role), ['active' => 'Ativa', 'suspended' => 'Suspensa', 'removed' => 'Retirada'][$m->status] ?? $m->status, $m->created_at]))
            ->section('Contas e cartões', ['Conta' => 'text', 'Tipo' => 'text', 'Banco' => 'text', 'Saldo inicial' => 'money', 'Limite' => 'money', 'Fecha dia' => 'int', 'Vence dia' => 'int', 'Arquivada em' => 'date'],
                $accounts->map(fn (Account $a) => [$a->name, $a->kindLabel(), $a->institution, $a->opening_balance_cents, $a->limit_cents, $a->closing_day, $a->due_day, $a->archived_at]))
            ->section('Categorias', ['Tipo' => 'text', 'Categoria' => 'text', 'Escondida' => 'text'],
                $categories->sortBy(fn ($c) => [$c->kind, $c->fullName()])->map(fn (Category $c) => [Category::KINDS[$c->kind] ?? $c->kind, $c->fullName(), $c->hidden_at ? 'Sim' : 'Não'])->values())
            ->section('Regras automáticas', ['Quando a descrição tiver' => 'text', 'Tipo' => 'text', 'Categoria' => 'text', 'Usada' => 'int'],
                CategoryRule::query()->orderBy('pattern')->get()->map(fn ($r) => [$r->pattern, Category::KINDS[$r->kind] ?? $r->kind, $category($r->category_id), $r->hits]))
            ->section('Lançamentos', [
                'Data' => 'date', 'Descrição' => 'text', 'Tipo' => 'text', 'Categoria' => 'text', 'Conta' => 'text', 'Para a conta' => 'text',
                'Valor' => 'money', 'Situação' => 'text', 'Parcela' => 'text', 'Fatura' => 'text', 'Origem' => 'text', 'Observação' => 'text',
            ], Transaction::query()->with(['account', 'toAccount'])->orderBy('date')->orderBy('created_at')->get()->map(fn (Transaction $t) => [
                $t->date, $t->description, Transaction::KINDS[$t->kind] ?? $t->kind, $category($t->category_id), $t->account?->name, $t->toAccount?->name,
                $t->kind === Transaction::EXPENSE ? -$t->amount_cents : $t->amount_cents, Transaction::STATUSES[$t->status] ?? $t->status,
                $t->seriesLabel(), $t->invoice_month, Transaction::SOURCES[$t->source] ?? $t->source, $t->notes,
            ]))
            ->section('Orçamento', ['Categoria' => 'text', 'Limite' => 'money', 'Mês' => 'text'],
                Budget::query()->get()->map(fn ($b) => [$category($b->category_id), $b->amount_cents, $b->month ?: 'Todo mês']))
            ->section('Metas', ['Meta' => 'text', 'Quanto quer juntar' => 'money', 'Já tinha' => 'money', 'Prazo' => 'date', 'Alcançada em' => 'date', 'Arquivada em' => 'date'],
                Goal::query()->orderBy('created_at')->get()->map(fn (Goal $g) => [$g->name, $g->target_cents, $g->start_cents, $g->deadline, $g->achieved_at, $g->archived_at]))
            ->section('Metas: guardei e tirei', ['Meta' => 'text', 'Data' => 'date', 'Valor' => 'money', 'Nota' => 'text'],
                GoalEntry::query()->with('goal')->orderBy('date')->get()->map(fn ($e) => [$e->goal?->name, $e->date, $e->amount_cents, $e->note]))
            ->section('Quem me deve: pessoas', ['Pessoa' => 'text', 'Telefone' => 'text', 'Arquivada em' => 'date'],
                Contact::query()->orderBy('name')->get()->map(fn (Contact $c) => [$c->name, $c->phone, $c->archived_at]))
            ->section('Quem me deve: movimentos', ['Pessoa' => 'text', 'Telefone' => 'text', 'Data' => 'date', 'O quê' => 'text', 'Valor (+ te deve, − você deve)' => 'money', 'Prazo' => 'date', 'Devolvido em' => 'date'],
                PersonEntry::query()->with('contact')->orderBy('date')->get()->map(fn ($e) => [
                    $e->contact?->name, $e->contact?->phone, $e->date, $e->label().($e->description ? ': '.$e->description : ''), $e->amount_cents, $e->due_date, $e->returned_at,
                ]))
            ->section('Despesas em grupo', ['Grupo' => 'text', 'Data' => 'date', 'O quê' => 'text', 'Quem pagou' => 'text', 'Valor' => 'money'],
                GroupExpense::query()->with('payer')->orderBy('date')->get()->map(fn ($e) => [
                    $groups->get($e->group_id)?->name, $e->date, $e->description, $e->payer?->label(), $e->amount_cents,
                ]))
            ->section('Sócios', ['Sócio' => 'text', 'Parte' => 'text', 'Arquivado em' => 'date'],
                Partner::query()->orderBy('name')->get()->map(fn ($p) => [$p->name, $p->shareLabel(), $p->archived_at]))
            ->section('Conquistas e pontos', ['Quando' => 'date', 'O quê' => 'text', 'Pontos' => 'int'],
                Reward::query()->whereIn('kind', [Reward::POINTS, Reward::BADGE])->orderBy('created_at')->get()->map(fn ($r) => [$r->created_at, $r->label, $r->points]));
    }
}
