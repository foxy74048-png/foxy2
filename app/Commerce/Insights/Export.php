<?php

namespace App\Commerce\Insights;

use App\Commerce\Catalog\Money;
use App\Commerce\Stores\Store;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportação de qualquer lista ou relatório (botões "Planilha" e "PDF"). A tela monta as seções com as
 * mesmas consultas e filtros que mostra; aqui só se formata. Tipos de coluna: text, int, decimal, money
 * (centavos), percent, date, datetime; com ":planilha" no fim (ex.: "text:planilha") a coluna só vai para a planilha. Planilha = CSV com ; e BOM (abre direto no Excel em português),
 * números sem "R$" para dar conta; PDF = tabela com o nome da loja, filtros, data e páginas numeradas.
 */
class Export
{
    public const FORMATS = ['planilha', 'pdf'];

    /** PDF muito grande fica lento e ninguém lê: acima disso, só a planilha tem tudo. */
    public const PDF_MAX_ROWS = 1500;

    private array $sections = [];

    private array $filters = [];

    public function __construct(private string $title) {}

    public static function requested(Request $request): ?string
    {
        $format = $request->query('exportar');

        return in_array($format, self::FORMATS, true) ? $format : null;
    }

    /** Filtros aplicados, para aparecer no PDF (valores vazios são ignorados). */
    public function filters(array $filters): static
    {
        $this->filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        return $this;
    }

    /** @param  array<string, string>  $columns  rótulo => tipo */
    public function section(?string $title, array $columns, iterable $rows): static
    {
        $this->sections[] = ['title' => $title, 'columns' => $columns, 'rows' => $rows];

        return $this;
    }

    public function download(string $format): Response
    {
        $name = Str::slug($this->title).'-'.now()->format('Y-m-d');

        return $format === 'pdf' ? $this->pdf($name.'.pdf') : $this->spreadsheet($name.'.csv');
    }

    private function spreadsheet(string $filename): Response
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($this->sections as $i => $section) {
                if ($i > 0) {
                    fwrite($out, "\r\n");
                }
                if ($section['title'] && count($this->sections) > 1) {
                    Csv::row($out, [$section['title']]);
                }
                Csv::row($out, array_keys($section['columns']));
                $types = array_map(fn ($t) => explode(':', $t)[0], array_values($section['columns']));
                foreach ($section['rows'] as $row) {
                    Csv::row($out, array_map(fn ($v, $t) => self::forSpreadsheet($v, $t), array_values($row), $types));
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(string $filename): Response
    {
        $truncated = false;
        $sections = array_map(function ($section) use (&$truncated) {
            $rows = [];
            // Posições das colunas que vão para o PDF (as ":planilha" ficam só na planilha).
            $keep = array_keys(array_filter(array_values($section['columns']), fn ($t) => ! str_ends_with($t, ':planilha')));
            $columns = array_filter($section['columns'], fn ($t) => ! str_ends_with($t, ':planilha'));
            $types = array_values($columns);
            foreach ($section['rows'] as $row) {
                if (count($rows) >= self::PDF_MAX_ROWS) {
                    $truncated = true;
                    break;
                }
                $values = array_values($row);
                $rows[] = array_map(fn ($i, $t) => self::forPdf($values[$i] ?? null, $t), $keep, $types);
            }

            return ['columns' => $columns, 'types' => $types, 'cells' => $rows] + $section;
        }, $this->sections);

        $wide = max(array_map(fn ($s) => count($s['columns']), $sections) ?: [0]) > 6;
        $html = view('commerce.export.pdf', [
            'title' => $this->title,
            'store' => Store::first()?->name,
            'filters' => $this->filters,
            'sections' => $sections,
            'truncated' => $truncated,
            'generatedAt' => now()->timezone('America/Sao_Paulo'),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', $wide ? 'landscape' : 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 8, [0.42, 0.41, 0.5]);

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private static function forSpreadsheet(mixed $value, string $type): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'money' => number_format(((int) $value) / 100, 2, ',', ''),
            'decimal' => number_format((float) $value, 2, ',', ''),
            'percent' => number_format((float) $value, 1, ',', ''),
            'date' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : (string) $value,
            'int' => (int) $value,
            default => (string) $value,
        };
    }

    private static function forPdf(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($type) {
            'money' => Money::format((int) $value),
            'decimal' => number_format((float) $value, 2, ',', '.'),
            'percent' => number_format((float) $value, 1, ',', '.').'%',
            'int' => number_format((int) $value, 0, ',', '.'),
            'date' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y') : (string) $value,
            'datetime' => $value instanceof \DateTimeInterface ? $value->format('d/m/Y H:i') : (string) $value,
            default => (string) $value,
        };
    }
}
