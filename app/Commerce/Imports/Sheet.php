<?php

namespace App\Commerce\Imports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Lê planilhas que a lojista já tem: .xlsx (Excel, Google Planilhas › Baixar) e .csv (com ; , ou tab, em
 * UTF-8 ou no padrão do Excel em português). Sem biblioteca: o .xlsx é um zip de XML. Devolve as linhas com
 * os títulos das colunas normalizados (minúsculas, sem acento) e o número da linha na planilha.
 */
class Sheet
{
    public const MAX_ROWS = 5000;

    private const MAX_XML_BYTES = 60 * 1024 * 1024;

    /**
     * @return array<int, array<string, string>> nº da linha na planilha => [título normalizado => valor]
     */
    public static function read(string $path, string $originalName): array
    {
        $ext = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $grid = match ($ext) {
            'xlsx' => self::xlsx($path),
            'csv', 'txt' => self::csv((string) file_get_contents($path)),
            'xls' => throw ValidationException::withMessages(['file' => 'Este é o formato antigo do Excel (.xls). No Excel, use Arquivo › Salvar como › "Pasta de Trabalho do Excel (.xlsx)" e envie de novo.']),
            default => throw ValidationException::withMessages(['file' => 'Envie a planilha em .xlsx (Excel) ou .csv.']),
        };

        return self::withHeader($grid);
    }

    /** Título de coluna → forma comparável: "Preço de venda (R$)" → "preco de venda r". */
    public static function key(string $title): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($title))));
    }

    /** Primeira coluna (pelos apelidos aceitos) que existe na linha e tem valor. */
    public static function pick(array $row, array $aliases): string
    {
        foreach ($aliases as $alias) {
            if (isset($row[$alias]) && trim($row[$alias]) !== '') {
                return trim($row[$alias]);
            }
        }

        return '';
    }

    /** "R$ 1.234,56", "1234.56", "89,9", 89.9 → centavos. Vazio ou inválido → null. */
    public static function cents(string $value): ?int
    {
        $v = preg_replace('/[^\d,.\-]/', '', $value);
        if ($v === '' || $v === '-') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace(',', '.', str_replace('.', '', $v));
        } elseif (substr_count($v, '.') > 1) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? (int) round(((float) $v) * 100) : null;
    }

    /** "10/11/2026", "10/11/26", "2026-11-10", número de série do Excel → Y-m-d. */
    public static function date(string $value): ?string
    {
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})/', $v, $m)) {
            $year = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $year) ? sprintf('%04d-%02d-%02d', $year, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }

        return null;
    }

    /** "3", "3,0", "3.0" → 3; vazio → null; texto → false. */
    public static function int(string $value): int|null|false
    {
        $v = str_replace(',', '.', trim($value));
        if ($v === '') {
            return null;
        }

        return is_numeric($v) && (float) $v == (int) $v ? (int) $v : false;
    }

    /** @param  array<int, string[]>  $grid */
    private static function withHeader(array $grid): array
    {
        // O título é a primeira linha com pelo menos dois textos (pula linhas de título da planilha).
        $headerAt = null;
        foreach (array_slice($grid, 0, 10, true) as $i => $cells) {
            if (count(array_filter($cells, fn ($c) => trim($c) !== '' && ! is_numeric(trim($c)))) >= 2) {
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            throw ValidationException::withMessages(['file' => 'Não achamos a linha com os títulos das colunas. A primeira linha da planilha deve ter os nomes (ex.: Produto, Tamanho, Cor, Quantidade, Preço).']);
        }

        $keys = array_map(fn ($t) => self::key((string) $t), $grid[$headerAt]);
        $rows = [];
        foreach ($grid as $i => $cells) {
            if ($i <= $headerAt || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($keys as $c => $key) {
                if ($key !== '' && ! isset($row[$key])) {
                    $row[$key] = trim((string) ($cells[$c] ?? ''));
                }
            }
            $rows[$i + 1] = $row;
            if (count($rows) > self::MAX_ROWS) {
                throw ValidationException::withMessages(['file' => 'A planilha passa de '.self::MAX_ROWS.' linhas. Divida em partes e envie uma de cada vez.']);
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'A planilha não tem linhas abaixo dos títulos.']);
        }

        return $rows;
    }

    /** @return array<int, string[]> */
    private static function csv(string $bytes): array
    {
        $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        $first = strtok($bytes, "\n") ?: '';
        $delimiter = collect([';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $bytes);
        rewind($stream);
        $grid = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $grid[] = array_map(fn ($c) => (string) $c, $cells);
            if (count($grid) > self::MAX_ROWS + 20) {
                break;
            }
        }
        fclose($stream);

        return $grid;
    }

    /** @return array<int, string[]> primeira aba */
    private static function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Não conseguimos abrir esta planilha. Salve de novo como .xlsx (ou .csv) e envie.']);
        }

        try {
            $sheetPath = self::firstSheetPath($zip);
            $shared = [];
            if (($xml = self::entry($zip, 'xl/sharedStrings.xml')) !== null) {
                foreach (simplexml_load_string($xml)->si ?? [] as $si) {
                    $shared[] = isset($si->t) ? (string) $si->t : collect($si->r)->map(fn ($r) => (string) $r->t)->implode('');
                }
            }
            $xml = self::entry($zip, $sheetPath) ?? throw ValidationException::withMessages(['file' => 'A planilha está vazia.']);
        } finally {
            $zip->close();
        }

        $sheet = simplexml_load_string($xml);
        $grid = [];
        foreach ($sheet->sheetData->row ?? [] as $row) {
            $r = (int) $row['r'] - 1;
            $cells = [];
            foreach ($row->c as $c) {
                $col = self::column((string) $c['r']);
                $type = (string) $c['t'];
                $cells[$col] = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? collect($c->is->r)->map(fn ($x) => (string) $x->t)->implode('')),
                    'b' => ((string) $c->v) === '1' ? 'sim' : 'não',
                    default => self::number((string) $c->v),
                };
            }
            if ($cells !== []) {
                $grid[$r] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
            if (count($grid) > self::MAX_ROWS + 20) {
                break;
            }
        }

        return $grid;
    }

    /** 89.900000000000006 → "89.9" (como o Excel mostra). */
    private static function number(string $v): string
    {
        return is_numeric($v) && str_contains($v, '.') ? rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.') : $v;
    }

    private static function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = self::entry($zip, 'xl/workbook.xml');
        $rels = self::entry($zip, 'xl/_rels/workbook.xml.rels');
        if ($workbook && $rels) {
            $wb = simplexml_load_string($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
            foreach (simplexml_load_string($rels)->Relationship ?? [] as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');

                    return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private static function entry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw ValidationException::withMessages(['file' => 'Planilha grande demais. Divida em partes e envie uma de cada vez.']);
        }

        return $zip->getFromName($name) ?: null;
    }

    /** "C12" → 2 */
    private static function column(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;
        foreach (str_split($letters) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return max(0, $n - 1);
    }
}
