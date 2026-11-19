<?php

namespace App\Platform\Imports;

use InvalidArgumentException;
use Smalot\PdfParser\Parser;
use ZipArchive;

/**
 * Lê um arquivo como tabela "crua" (lista de linhas, cada linha uma lista de células em texto), sem adivinhar
 * cabeçalho: é o que a importação de extrato precisa, porque a pessoa aponta as colunas e o extrato em PDF
 * tem títulos de dia, saldos e cabeçalhos repetidos no meio. (A Sheet, ao lado, devolve linhas com cabeçalho.)
 *
 * Copiado do SpreadsheetReader do Foxy Studio (docs/12, A-F2), que já foi testado com arquivos reais:
 * - CSV/TXT: detecta o separador (; , tab |), aceita UTF-8 e ISO-8859-1 e células com quebra de linha;
 * - XLSX: lido direto do zip/XML (sem biblioteca), primeira aba;
 * - PDF com texto: monta a tabela pelas posições do texto na página (colunas descobertas pelas posições
 *   horizontais que se repetem; valores alinhados à direita agrupados com folga maior).
 */
class RawTable
{
    public const MAX_ROWS = 10000;

    /** @return array<int, array<int, string>> */
    public function read(string $path, string $extension): array
    {
        $table = match (strtolower($extension)) {
            'csv', 'txt' => $this->csv((string) file_get_contents($path)),
            'xlsx' => $this->xlsx($path),
            'pdf' => $this->pdf($path),
            'xls' => throw new InvalidArgumentException('Esse é o formato antigo do Excel (.xls). Abra o arquivo no Excel ou no Google Planilhas e salve como .xlsx ou .csv.'),
            default => throw new InvalidArgumentException('Formato não suportado. Envie .ofx, .xlsx, .csv ou .pdf.'),
        };

        $table = array_values(array_filter($table, fn (array $row) => implode('', $row) !== ''));
        if ($table === []) {
            throw new InvalidArgumentException('Não encontrei nenhuma linha preenchida nesse arquivo.');
        }

        return array_slice($table, 0, self::MAX_ROWS + 1);
    }

    /** @return array<int, array<int, string>> */
    public function csv(string $content): array
    {
        $content = self::toUtf8($content);
        $sample = array_slice(preg_split('/\r\n|\r|\n/', $content), 0, 5);
        $delimiter = $this->detectDelimiter($sample);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $table = [];
        while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $table[] = array_map(fn ($cell) => trim((string) $cell), $cells);
        }
        fclose($stream);

        return $table;
    }

    /** @return array<int, array<int, string>> */
    public function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Não consegui abrir esse arquivo do Excel. Tente salvar de novo como .xlsx ou .csv.');
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                // Texto com formatação vem quebrado em vários <r><t>.
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
            }
        }

        $sheetPath = $this->firstSheetPath($zip);
        $xml = $zip->getFromName($sheetPath);
        $zip->close();
        if ($xml === false) {
            throw new InvalidArgumentException('Não encontrei nenhuma aba com dados nesse Excel.');
        }

        $doc = simplexml_load_string($xml);
        $table = [];
        foreach ($doc->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $index = $this->columnIndex((string) $cell['r']) ?? count($cells);
                $value = match ((string) $cell['t']) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    'b' => ((string) $cell->v) === '1' ? 'sim' : 'não',
                    default => self::plainNumber((string) $cell->v),
                };
                $cells[$index] = trim($value);
            }
            if ($cells === []) {
                continue;
            }
            $max = max(array_keys($cells));
            $table[] = array_map(fn ($i) => $cells[$i] ?? '', range(0, $max));
            if (count($table) > self::MAX_ROWS) {
                break;
            }
        }

        return $table;
    }

    /** @return array<int, array<int, string>> */
    public function pdf(string $path): array
    {
        try {
            $document = (new Parser)->parseFile($path);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Não consegui abrir esse PDF. Se ele tiver senha, tire a senha antes; se puder, exporte em Excel, CSV ou OFX.');
        }

        $lines = [];
        foreach ($document->getPages() as $page) {
            $rows = [];
            foreach ($page->getDataTm() as [$matrix, $text]) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }
                $x = (float) ($matrix[4] ?? 0);
                $y = (float) ($matrix[5] ?? 0);
                // Mesma linha visual: y a até 3 pontos de distância.
                $key = null;
                foreach (array_keys($rows) as $existingY) {
                    if (abs($existingY - $y) <= 3) {
                        $key = $existingY;
                        break;
                    }
                }
                $rows[$key ?? (string) $y][] = [$x, $text];
            }
            krsort($rows, SORT_NUMERIC); // PDF conta y de baixo para cima
            foreach ($rows as $fragments) {
                usort($fragments, fn ($a, $b) => $a[0] <=> $b[0]);
                $lines[] = $fragments;
            }
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Esse PDF não tem texto para ler — parece ser uma imagem ou digitalização. Baixe o extrato em OFX, Excel ou CSV no app do banco (ou gere o PDF de novo pelo app, não escaneado).');
        }

        $columns = $this->pdfColumnStarts($lines);

        $table = [];
        foreach ($lines as $fragments) {
            $cells = array_fill(0, count($columns), '');
            foreach ($fragments as [$x, $text]) {
                $column = 0;
                foreach ($columns as $i => $start) {
                    if ($x >= $start) {
                        $column = $i;
                    }
                }
                $cells[$column] = trim($cells[$column].' '.$text);
            }
            $table[] = $cells;
        }

        // Cabeçalho repetido no topo de cada página não vira linha de dado.
        $header = $table[0];

        return array_values(array_filter($table, fn ($row, $i) => $i === 0 || $row !== $header, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Onde começa cada coluna: agrupa as posições x de todas as linhas em blocos e fica com os blocos presentes
     * em pelo menos 25% das linhas. Texto se agrupa com posições a até 14 pt; valores em dinheiro, a até 45 pt
     * (alinhados à direita, "12.300,00" começa bem mais à esquerda que "2,50" na mesma coluna).
     *
     * @param  array<int, array<int, array{0: float, 1: string}>>  $lines
     * @return array<int, float>
     */
    private function pdfColumnStarts(array $lines): array
    {
        $points = [];
        foreach ($lines as $lineIndex => $fragments) {
            foreach ($fragments as [$x, $text]) {
                $points[] = [$x, $lineIndex, (bool) preg_match('/^[-+(]?\s*(R\$)?\s*-?[\d.]*\d,\d{2}\s*[-)DC]?$/u', $text)];
            }
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        $clusters = [];
        foreach ($points as [$x, $lineIndex, $isMoney]) {
            $last = array_key_last($clusters);
            $gap = $isMoney && $last !== null && $clusters[$last]['money'] ? 45 : 14;
            if ($last !== null && $x - $clusters[$last]['max'] <= $gap) {
                $clusters[$last]['max'] = $x;
                $clusters[$last]['lines'][$lineIndex] = true;
                $clusters[$last]['money'] = $clusters[$last]['money'] || $isMoney;
            } else {
                $clusters[] = ['min' => $x, 'max' => $x, 'lines' => [$lineIndex => true], 'money' => $isMoney];
            }
        }

        $minHits = max(2, (int) ceil(count($lines) * 0.25));
        $starts = array_values(array_map(
            fn ($cluster) => $cluster['min'] - 1,
            array_filter($clusters, fn ($cluster) => count($cluster['lines']) >= $minHits)
        ));

        return $starts !== [] ? $starts : [0.0];
    }

    public static function plainNumber(string $value): string
    {
        if (! preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $value)) {
            return $value;
        }

        $number = (float) $value;
        if (floor($number) === $number && abs($number) < 1e15) {
            return sprintf('%.0f', $number);
        }

        return $value;
    }

    public static function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Muitos bancos brasileiros ainda exportam em ISO-8859-1 / Windows-1252.
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $wb = simplexml_load_string($workbook);
            $sheet = $wb->sheets->sheet[0] ?? null;
            $relId = $sheet ? (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $relId) {
                    $target = ltrim((string) $rel['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** "C12" → 2 */
    private function columnIndex(string $reference): ?int
    {
        if (! preg_match('/^([A-Z]+)/', $reference, $m)) {
            return null;
        }
        $index = 0;
        foreach (str_split($m[1]) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }

    /** @param  array<int, string>  $sample */
    private function detectDelimiter(array $sample): string
    {
        $scores = [];
        foreach ([';', ',', "\t", '|'] as $delimiter) {
            $scores[$delimiter] = array_sum(array_map(fn ($line) => substr_count($line, $delimiter), $sample));
        }
        arsort($scores);

        return (string) array_key_first($scores);
    }
}
