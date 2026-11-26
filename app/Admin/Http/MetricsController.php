<?php

namespace App\Admin\Http;

use App\Admin\Metrics\ProductMetrics;
use App\Platform\Exports\Export;
use App\Platform\Integrations\Integration;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Métricas do Foxy Admin (docs/08, seção 3.5): MRR, conversão, churn, ativação e turmas de cadastro, por produto.
 */
class MetricsController
{
    public function index(Request $request): mixed
    {
        $filters = $request->validate([
            'produto' => ['nullable', Rule::exists('integrations', 'id')],
            'meses' => ['nullable', Rule::in(ProductMetrics::MONTHS)],
        ]);
        $months = (int) ($filters['meses'] ?? 12);
        $integrations = Integration::orderBy('name')->get();
        $metrics = new ProductMetrics($filters['produto'] ?? null, $months);

        $data = [
            'summary' => $metrics->summary(),
            'monthly' => $metrics->monthly(),
            'cohorts' => $metrics->cohorts(),
            'since' => $metrics->historySince(),
            'integrations' => $integrations,
            'filters' => $filters + ['meses' => $months],
        ];

        if ($format = Export::requested($request)) {
            return $this->export($data, $integrations->firstWhere('id', $filters['produto'] ?? null)?->name, $format);
        }

        return view('admin.metrics', $data);
    }

    private function export(array $data, ?string $product, string $format): Response
    {
        $retention = fn (array $c, int $n) => $c['retention'][$n]['pct'] ?? null;

        return (new Export('Métricas da Foxy'))
            ->filters(['Produto' => $product ?? 'Todos', 'Meses' => (string) $data['filters']['meses'], 'Histórico desde' => $data['since']?->timezone('America/Sao_Paulo')->format('d/m/Y')])
            ->section('Mês a mês', ['Mês' => 'text', 'Cadastros' => 'int', 'Novos pagantes' => 'int', 'Saíram (pagantes)' => 'int', 'MRR perdido' => 'decimal', 'Pagantes no início' => 'int', 'Pagantes no fim' => 'int', 'MRR no fim' => 'decimal', 'Churn %' => 'percent'],
                collect($data['monthly'])->map(fn ($m) => [$m['label'], $m['signups'], $m['new_paying'], $m['lost'], $m['mrr_lost'], $m['paying_start'], $m['paying_end'], $m['mrr_end'], $m['churn']]))
            ->section('Turmas por mês de cadastro', ['Cadastro' => 'text', 'Contas' => 'int', 'Ativaram em 7 dias' => 'int', 'Ativação %' => 'percent', 'Dias até o 1º uso (mediana)' => 'decimal', 'Viraram pagantes' => 'int', 'Conversão %' => 'percent', 'Em uso após 1 mês %' => 'percent', 'Após 2 meses %' => 'percent', 'Após 3 meses %' => 'percent'],
                collect($data['cohorts'])->map(fn ($c) => [$c['label'], $c['signups'], $c['activated_7d'], $c['signups'] ? round($c['activated_7d'] / $c['signups'] * 100, 1) : null, $c['median_days'], $c['paid'], $c['conversion'], $retention($c, 1), $retention($c, 2), $retention($c, 3)]))
            ->download($format);
    }
}
