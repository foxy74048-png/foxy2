<?php

namespace App\Commerce\Http;

use App\Commerce\Insights\Export;
use App\Commerce\Insights\FinanceReport;
use App\Commerce\Insights\Reports;
use App\Commerce\Orders\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Relatórios (dono e gerente): mês a mês, estoque parado e giro, curva ABC.
 */
class ReportController
{
    public const TABS = ['mensal' => 'Mês a mês', 'estoque' => 'Estoque parado e giro', 'abc' => 'Curva ABC'];

    public const STOCK_STATUS = [
        'parado' => 'Parado (sem venda no período)', 'lento' => 'Lento (estoque para mais de 4 meses)', 'acabando' => 'Acabando (menos de 15 dias)',
        'sem_estoque' => 'Sem estoque', 'saudavel' => 'Saudável',
    ];

    public function index(Request $request, Reports $reports): View|Response
    {
        $filters = $request->validate([
            'aba' => ['nullable', Rule::in(array_keys(self::TABS))],
            'meses' => ['nullable', 'integer', Rule::in([6, 12, 24])],
            'canal' => ['nullable', Rule::in(array_keys(Order::CHANNELS))],
            'dias' => ['nullable', 'integer', Rule::in([30, 60, 90, 180])],
            'situacao' => ['nullable', Rule::in(array_keys(self::STOCK_STATUS))],
            'periodo' => ['nullable', Rule::in(array_keys(FinanceReport::PERIODS))],
            'de' => ['nullable', 'date'],
            'ate' => ['nullable', 'date', 'after_or_equal:de'],
            'classe' => ['nullable', Rule::in(['A', 'B', 'C'])],
        ]);
        $tab = $filters['aba'] ?? 'mensal';
        $data = ['tab' => $tab, 'filters' => $filters];

        if ($tab === 'mensal') {
            $data['months'] = $reports->monthly((int) ($filters['meses'] ?? 12), $filters['canal'] ?? null);
        } elseif ($tab === 'estoque') {
            $rows = $reports->stock((int) ($filters['dias'] ?? 90));
            $data['summary'] = ['stuck' => $rows->whereIn('status', ['parado', 'lento'])->sum('stuck_cents'), 'total' => $rows->sum('stuck_cents'), 'counts' => $rows->countBy('status')];
            $data['rows'] = isset($filters['situacao']) ? $rows->where('status', $filters['situacao'])->values() : $rows;
        } else {
            [$from, $to] = FinanceReport::range($filters['periodo'] ?? '90d', $filters['de'] ?? null, $filters['ate'] ?? null);
            $rows = $reports->abc($from, $to);
            $data += ['from' => $from, 'to' => $to, 'classes' => $rows->groupBy('class')->map(fn ($g) => ['count' => $g->count(), 'revenue' => $g->sum('revenue')])];
            $data['rows'] = isset($filters['classe']) ? $rows->where('class', $filters['classe'])->values() : $rows;
        }

        if ($format = Export::requested($request)) {
            return $this->export($data, $filters, $format);
        }

        return view('commerce.reports', $data);
    }

    private function export(array $data, array $filters, string $format): Response
    {
        return (match ($data['tab']) {
            'mensal' => (new Export('Relatório mês a mês'))
                ->filters(['Meses' => (string) ($filters['meses'] ?? 12), 'Canal' => isset($filters['canal']) ? Order::CHANNELS[$filters['canal']] : 'Todos'])
                ->section(null, ['Mês' => 'text', 'Pedidos' => 'int', 'Faturamento' => 'money', 'Variação %' => 'percent', 'Ticket médio' => 'money', 'Lucro bruto' => 'money', 'Margem %' => 'percent'],
                    $data['months']->map(fn ($m) => [$m['full'], $m['orders'], $m['revenue'], $m['delta'], $m['ticket'], $m['profit'], $m['margin']])),
            'estoque' => $this->stockExport($data['rows'], (int) ($filters['dias'] ?? 90), $filters['situacao'] ?? null),
            default => (new Export('Curva ABC dos produtos'))
                ->filters(['Período' => $data['from']->format('d/m/Y').' a '.$data['to']->format('d/m/Y'), 'Classe' => $filters['classe'] ?? null])
                ->section(null, ['Classe' => 'text', 'Produto' => 'text', 'Quantidade' => 'int', 'Faturamento' => 'money', 'Participação %' => 'percent', 'Acumulado %' => 'percent', 'Lucro bruto' => 'money', 'Margem %' => 'percent'],
                    $data['rows']->map(fn ($r) => [$r['class'], $r['label'], $r['quantity'], $r['revenue'], $r['share'], $r['cumulative'], $r['profit'], $r['margin']])),
        })->download($format);
    }

    private function stockExport($rows, int $days, ?string $status): Export
    {
        return (new Export('Estoque parado e giro'))
            ->filters(['Vendas dos últimos' => $days.' dias', 'Situação' => $status ? self::STOCK_STATUS[$status] : null])
            ->section(null, ['SKU' => 'text', 'Produto' => 'text', 'Físico' => 'int', "Vendido ({$days} dias)" => 'int', 'Por dia' => 'decimal', 'Dura (dias)' => 'int', 'Última venda' => 'date', 'Dinheiro parado (custo)' => 'money', 'Situação' => 'text'],
                $rows->map(fn ($r) => [$r['variant']->sku, $r['variant']->fullName(), $r['on_hand'], $r['sold'], $r['per_day'], $r['coverage'], $r['last_sale'], $r['stuck_cents'], self::STOCK_STATUS[$r['status']]]));
    }

    /** Planilha do estoque parado e giro (endereço antigo, continua funcionando). */
    public function stockCsv(Request $request, Reports $reports): Response
    {
        $days = in_array((int) $request->query('dias'), [30, 60, 90, 180], true) ? (int) $request->query('dias') : 90;

        return $this->stockExport($reports->stock($days), $days, null)->download('planilha');
    }
}
