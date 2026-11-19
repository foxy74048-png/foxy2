<?php

namespace App\Finance\Http;

use App\Finance\Access;
use App\Finance\Budgets\Budget;
use App\Finance\Categories\Category;
use App\Finance\Insights\Export;
use App\Finance\Insights\Indicators;
use App\Platform\Audit\Audit;
use App\Platform\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orçamento (fatia 6): limite de gasto por categoria, todo mês ou só num mês, com a barra de quanto já foi e o
 * aviso ao passar de 80% e de 100% (na tela e no Início).
 */
class BudgetController
{
    public function index(Request $request): View|Response
    {
        $wanted = $request->query('mes');
        $month = is_string($wanted) && preg_match('/^\d{4}-\d{2}$/', $wanted) ? Carbon::createFromFormat('Y-m-d', $wanted.'-01')->startOfDay() : today()->startOfMonth();
        $indicators = Indicators::for($month);
        $rows = $indicators->budgets();

        if ($format = Export::requested($request)) {
            return (new Export('Orçamento'))->filters(['Mês' => $month->format('m/Y')])
                ->section(null, ['Categoria' => 'text', 'Vale' => 'text', 'Limite' => 'money', 'Gasto' => 'money', 'Sobra' => 'money', 'Usado (%)' => 'percent'],
                    $rows->map(fn ($b) => [$b['category']->fullName(), $b['budget']->month ? 'só neste mês' : 'todo mês', $b['limit'], $b['spent'], $b['limit'] - $b['spent'], $b['pct'] * 100]))
                ->download($format);
        }

        $budgeted = $rows->pluck('category.id')->all();

        return view('finance.budgets', [
            'month' => $month,
            'rows' => $rows,
            'totalLimit' => $rows->sum('limit'),
            'totalSpent' => $rows->sum('spent'),
            // Sugestão para começar: os grupos onde mais gasta e ainda sem limite, com a média dos 3 meses.
            'suggestions' => $indicators->categories()->filter(fn ($c) => $c['id'] && ! in_array($c['id'], $budgeted, true) && max($c['avg'], $c['total']) > 0)->take(5)->values(),
            'categoryOptions' => Category::options()['gasto'] ?? [],
            'canManage' => Access::allows($request->attributes->get('finance.membership'), 'finance.accounts.manage'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'string', 'max:20'],
            'only_month' => ['nullable', 'date_format:Y-m'],
        ], [], ['category_id' => 'categoria', 'amount' => 'limite']);
        $category = Category::find($data['category_id']);
        if (! $category || $category->kind !== 'gasto') {
            throw ValidationException::withMessages(['category_id' => 'Escolha uma categoria de gasto.']);
        }
        $cents = Money::toCents($data['amount']) ?? 0;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um limite maior que zero.']);
        }

        // Um limite por categoria (todo mês) e, se quiser, um diferente para um mês.
        $budget = Budget::updateOrCreate(['category_id' => $category->id, 'month' => $data['only_month'] ?? null], ['amount_cents' => $cents]);
        Audit::record('finance.budget.saved', subject: $budget);

        return back()->with('status', "Limite de {$category->fullName()}: ".Money::format($cents).(isset($data['only_month']) ? ' só em '.Carbon::createFromFormat('Y-m-d', $data['only_month'].'-01')->format('m/Y') : ' por mês').'.');
    }

    public function update(Request $request, Budget $budget): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'string', 'max:20']], [], ['amount' => 'limite']);
        $cents = Money::toCents($data['amount']) ?? 0;
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Informe um limite maior que zero.']);
        }
        $budget->update(['amount_cents' => $cents]);

        return back()->with('status', 'Limite salvo.');
    }

    public function destroy(Budget $budget): RedirectResponse
    {
        $budget->delete();
        Audit::record('finance.budget.deleted', subject: $budget);

        return back()->with('status', 'Limite apagado.');
    }
}
