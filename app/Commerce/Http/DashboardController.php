<?php

namespace App\Commerce\Http;

use App\Commerce\Access;
use App\Commerce\Catalog\Category;
use App\Commerce\Catalog\Money;
use App\Commerce\Consignment\Consignment;
use App\Commerce\Finance\Payable;
use App\Commerce\Finance\Receivable;
use App\Commerce\Insights\Export;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Inventory\StockLevel;
use App\Commerce\Orders\Order;
use App\Commerce\Supply\SupplyBatch;
use App\Platform\Context\ExecutionContext;
use App\Platform\Memberships\Membership;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Painel: números financeiros com filtros para quem vê relatórios (dono e gerente); resumo da operação para todos.
 */
class DashboardController
{
    /** Painel financeiro em PDF/planilha: indicadores e cada quadro do painel como uma seção. */
    private function export(array $f, array $filters, string $format): Response
    {
        $k = $f['kpis'];
        $report = $f['report'];
        $rows = fn ($c) => $c->map(fn ($r) => [$r['label'], $r['orders'], $r['revenue'], $r['ticket'], $r['fees'], $r['profit'], $r['margin']]);
        $groupCols = ['Nome' => 'text', 'Pedidos' => 'int', 'Faturamento' => 'money', 'Ticket médio' => 'money', 'Taxas' => 'money', 'Lucro bruto' => 'money', 'Margem %' => 'percent'];

        return (new Export('Painel financeiro'))
            ->filters([
                'Período' => $report->from->format('d/m/Y').' a '.$report->to->format('d/m/Y'),
                'Canal' => isset($filters['canal']) ? Order::CHANNELS[$filters['canal']] : null,
                'Pagamento' => isset($filters['pagamento']) ? Order::PAYMENT_METHODS[$filters['pagamento']] : null,
                'Categoria' => isset($filters['categoria']) ? Category::find($filters['categoria'])?->fullName() : null,
            ])
            ->section('Indicadores', ['Indicador' => 'text', 'Valor' => 'text'], [
                ['Faturamento', Money::format($k['revenue'])], ['Pedidos', (string) $k['orders']], ['Ticket médio', Money::format($k['ticket'])],
                ['Lucro bruto', Money::format($k['profit'])], ['Margem', number_format($k['margin'], 1, ',', '.').'%'],
                ['Taxas dos canais', Money::format($k['fees'])], ['Custo das peças', Money::format($k['cost'])], ['Descontos', Money::format($k['discounts'])], ['Frete', Money::format($k['shipping'])],
                ['Cancelados', $k['canceled_count'].' ('.Money::format($k['canceled_cents']).', '.number_format($k['cancel_rate'], 1, ',', '.').'%)'],
                ['Aguardando pagamento', $k['awaiting_count'].' ('.Money::format($k['awaiting_cents']).')'], ['Clientes novos', (string) $k['new_customers']],
            ])
            ->section('Vendas por '.$f['series']['unit'], ['Período' => 'text', 'Pedidos' => 'int', 'Faturamento' => 'money', 'Lucro bruto' => 'money'],
                collect($f['series']['points'])->map(fn ($p) => [$p['full'], $p['orders'], $p['revenue'], $p['profit']]))
            ->section('Por canal', $groupCols, $rows($f['channels']))
            ->section('Por forma de pagamento', $groupCols, $rows($f['payments']))
            ->section('Produtos que mais venderam', ['Produto' => 'text', 'Quantidade' => 'int', 'Faturamento' => 'money', 'Lucro bruto' => 'money'],
                $f['products']->map(fn ($r) => [$r['label'], $r['quantity'], $r['revenue'], $r['profit']]))
            ->section('Por categoria', ['Categoria' => 'text', 'Quantidade' => 'int', 'Faturamento' => 'money'],
                $f['categories']->map(fn ($r) => [$r['label'], $r['quantity'], $r['revenue']]))
            ->section('Clientes que mais compraram', ['Cliente' => 'text', 'Pedidos' => 'int', 'Total' => 'money'],
                $f['customers']->map(fn ($r) => [$r['label'], $r['orders'], $r['revenue']]))
            ->download($format);
    }

    public function index(Request $request): View|Response
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
            'withoutInvoice' => Order::sold()->where('placed_at', '>=', now()->subDays(29)->startOfDay())->whereDoesntHave('fiscalDocuments')->count(),
            'finance' => null,
            'alerts' => null,
            'maletas' => Access::allows($request->attributes->get('commerce.membership'), 'commerce.orders.manage')
                ? Consignment::with('customer')->where('status', 'aberta')->whereNotNull('due_on')->where('due_on', '<', today()->addDays(3)->toDateString())->orderBy('due_on')->limit(6)->get()
                : collect(),
        ];

        // Contas e acertos que pedem ação (dono e gerente).
        if (Access::allows($request->attributes->get('commerce.membership'), 'commerce.finance.manage')) {
            $today = today()->toDateString();
            $receivables = Receivable::open();
            $payables = Payable::open();
            $data['alerts'] = [
                'overdue' => (int) (clone $receivables)->where('due_date', '<', $today)->sum('amount_cents'),
                'overdue_count' => (clone $receivables)->where('due_date', '<', $today)->count(),
                'receive_week' => (int) (clone $receivables)->where('due_date', '>=', $today)->where('due_date', '<', today()->addDays(8)->toDateString())->sum('amount_cents'),
                'pay_week' => (int) (clone $payables)->where('due_date', '<', today()->addDays(8)->toDateString())->sum('amount_cents'),
                'pay_overdue' => (clone $payables)->where('due_date', '<', $today)->count(),
                'settlements' => SupplyBatch::where('status', 'aberta')->whereNotNull('closing_on')->where('closing_on', '<', today()->addDays(8)->toDateString())->orderBy('closing_on')->get(),
            ];
        }

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

        if (($format = Export::requested($request)) && $data['finance']) {
            return $this->export($data['finance'], $filters, $format);
        }

        return view('commerce.dashboard', $data);
    }
}
