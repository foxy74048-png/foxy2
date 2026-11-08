<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Catalog\Category;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Orders\Order;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Painel: números financeiros com filtros para quem vê relatórios (dono e gerente); resumo da operação para todos.
 */
class DashboardController
{
    public function index(Request $request): View
    {
        $organization = ExecutionContext::current()->organization;
        $filters = $request->validate([
            'periodo' => ['nullable', Rule::in(array_keys(FinanceReport::PERIODS))],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
            'canal' => ['nullable', Rule::in(array_keys(Order::CHANNELS))],
            'pagamento' => ['nullable', Rule::in(array_keys(Order::PAYMENT_METHODS))],
            'categoria' => ['nullable', 'string', 'max:30'],
        ]);

        $data = [
            'filters' => $filters,
            'teamCount' => Membership::active()->where('organization_id', $organization->getKey())->count(),
            // Peças (não kits) negativas ou no mínimo; o detalhe fica na tela de estoque.
            'lowStock' => StockLevel::whereHas('variant', fn ($q) => $q->where('status', 'active'))
                ->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhereRaw('min_stock is not null and on_hand - reserved <= min_stock'))
                ->count(),
            'toShip' => Order::whereIn('status', ['paid', 'in_fulfillment'])->count(),
            'awaiting' => Order::where('status', 'awaiting_payment')->count(),
            'finance' => null,
        ];

        if (Access::allows($request->attributes->get('commerce.membership'), 'commerce.reports.view')) {
            $report = new FinanceReport($filters);
            $data['finance'] = [
                'report' => $report,
                'kpis' => $report->kpis(),
                'series' => $report->series(),
                'channels' => $report->byChannel(),
                'payments' => $report->byPayment(),
                'products' => $report->topProducts(),
                'categories' => $report->byCategory(),
                'customers' => $report->topCustomers(),
                'categoryOptions' => Category::with('parent')->orderBy('name')->get(),
            ];
        }

        return view('commerce.dashboard', $data);
    }
}
