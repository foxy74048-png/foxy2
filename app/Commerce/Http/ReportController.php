<?php

namespace App\Commerce\Http;

use App\Commerce\Insights\FinanceReport;
use App\Commerce\Insights\Reports;
use App\Commerce\Orders\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function index(Request $request, Reports $reports): View
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

        return view('commerce.reports', $data);
    }

    /** Planilha do estoque parado e giro. */
    public function stockCsv(Request $request, Reports $reports): StreamedResponse
    {
        $days = in_array((int) $request->query('dias'), [30, 60, 90, 180], true) ? (int) $request->query('dias') : 90;
        $rows = $reports->stock($days);

        return response()->streamDownload(function () use ($rows, $days) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['SKU', 'Produto', 'Físico', "Vendido ({$days} dias)", 'Por dia', 'Dura (dias)', 'Última venda', 'Dinheiro parado (custo)', 'Situação'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['variant']->sku, $r['variant']->fullName(), $r['on_hand'], $r['sold'], number_format($r['per_day'], 2, ',', ''),
                    $r['coverage'] ?? '', $r['last_sale']?->format('d/m/Y') ?? '', number_format($r['stuck_cents'] / 100, 2, ',', ''), self::STOCK_STATUS[$r['status']]], ';');
            }
            fclose($out);
        }, 'estoque-giro-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
