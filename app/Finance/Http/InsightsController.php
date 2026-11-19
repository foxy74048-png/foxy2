<?php

namespace App\Finance\Http;

use App\Finance\Insights\Export;
use App\Finance\Insights\Indicators;
use App\Finance\Transactions\TransactionFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Onde vai o dinheiro" (fatia 6): o mês em números e os indicadores para economizar — quanto da renda guardou,
 * o ritmo do mês, o que mais subiu, onde mais gasta, gastinhos, assinaturas, reserva de emergência e "se cortar
 * pela metade". Tudo exporta (PDF e planilha) com as mesmas contas da tela.
 */
class InsightsController
{
    public function index(Request $request): View|Response
    {
        $month = $this->month($request);
        $i = Indicators::for($month);
        $data = [
            'month' => $month,
            'summary' => $i->summary(),
            'pace' => $i->pace(),
            'categories' => $i->categories(),
            'rises' => $i->rises(),
            'hasHistory' => $i->hasHistory(),
            'places' => $i->places(),
            'small' => $i->smallSpending(),
            'subscriptions' => $i->subscriptions(),
            'reserve' => $i->reserve(),
            'cut' => $i->cutInHalf(),
            'evolution' => $i->evolution(6),
            'budgets' => $i->budgets(),
        ];

        if ($format = Export::requested($request)) {
            return $this->export($format, $data);
        }

        return view('finance.insights', $data + [
            'tips' => \App\Finance\Assistant\AiNote::query()->where('kind', 'tips')->where('month', $month->format('Y-m'))->first(),
            'foxReady' => \App\Finance\Assistant\FinanceFox::isReady(),
            'neighbours' => [$month->copy()->subMonthNoOverflow()->format('Y-m'), $month->copy()->addMonthNoOverflow()->format('Y-m')],
        ]);
    }

    private function month(Request $request): Carbon
    {
        $wanted = $request->query('mes');

        return is_string($wanted) && preg_match('/^\d{4}-\d{2}$/', $wanted)
            ? Carbon::createFromFormat('Y-m-d', $wanted.'-01')->startOfDay()
            : today()->startOfMonth();
    }

    private function export(string $format, array $d): Response
    {
        $s = $d['summary'];
        $pct = fn (?float $v) => $v === null ? null : $v * 100;

        return (new Export('Onde vai o dinheiro'))
            ->filters(['Mês' => Str::ucfirst(TransactionFilters::monthName($d['month']))])
            ->section('Resumo', ['Entrou' => 'money', 'Saiu' => 'money', 'Sobrou' => 'money', 'Guardou da renda (%)' => 'percent', 'Média dos 3 meses antes (%)' => 'percent'],
                [[$s['income'], $s['expense'], $s['saved'], $pct($s['savings_rate']), $pct($s['avg_savings_rate'])]])
            ->section('Por categoria', ['Categoria' => 'text', 'No mês' => 'money', 'Média dos 3 meses antes' => 'money', 'Diferença' => 'money'],
                $d['categories']->map(fn ($c) => [$c['name'], $c['total'], $c['avg'], $c['delta']]))
            ->section('Onde mais gastou', ['Lugar' => 'text', 'Vezes' => 'int', 'Total' => 'money'], $d['places']->map(fn ($p) => [$p['label'], $p['count'], $p['total']]))
            ->section('Gastinhos (até R$ 30)', ['Compras no mês' => 'int', 'Total no mês' => 'money', 'Por ano nesse ritmo' => 'money'], [[$d['small']['count'], $d['small']['total'], $d['small']['per_year']]])
            ->section('Assinaturas', ['O quê' => 'text', 'Categoria' => 'text', 'Por mês' => 'money', 'Por ano' => 'money'],
                $d['subscriptions']->map(fn ($x) => [$x['label'], $x['category'], $x['monthly'], $x['yearly']]))
            ->section('Orçamento', ['Categoria' => 'text', 'Limite' => 'money', 'Gasto' => 'money', 'Usado (%)' => 'percent'],
                $d['budgets']->map(fn ($b) => [$b['category']->fullName(), $b['limit'], $b['spent'], $b['pct'] * 100]))
            ->section('Mês a mês', ['Mês' => 'text', 'Entrou' => 'money', 'Saiu' => 'money', 'Sobrou' => 'money'],
                $d['evolution']->map(fn ($e) => [$e['full'], $e['income'], $e['expense'], $e['saved']]))
            ->download($format);
    }
}
