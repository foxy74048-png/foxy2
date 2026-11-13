<?php

namespace App\Commerce\Imports;

use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Parser;

/**
 * Tabela de um PDF com texto (relatório de estoque, lista de contas, planilha "salva como PDF") → linhas e
 * colunas, como numa planilha. O PDF não guarda tabela, só pedaços de texto com posição. Então:
 *  1. junta os pedaços da mesma altura numa linha (e cola a palavra que o PDF partiu: "T" + "amanho");
 *  2. acha a linha de títulos (a que tem mais nomes de coluna conhecidos);
 *  3. aprende onde cada coluna começa e termina pelas linhas completas (título centralizado não engana);
 *  4. linha com um texto só (descrição que quebrou) vai para a linha da tabela mais perto, acima ou abaixo.
 * PDF escaneado (imagem, sem texto) não dá: pede a planilha.
 */
class PdfTable
{
    private const MAX_PAGES = 60;

    /**
     * @param  string[]  $known  títulos de coluna aceitos, já normalizados (Sheet::key)
     * @return array<int, string[]> linhas (a primeira é a dos títulos)
     */
    /** Largura média de uma letra neste PDF (para saber se dois pedaços são a mesma palavra). */
    private static float $letter = 5.0;

    public static function grid(string $path, array $known): array
    {
        $lines = self::read($path);
        self::$letter = self::letterWidth($lines);
        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'Este PDF não tem texto (parece uma foto ou um documento escaneado). Envie a planilha (.xlsx ou .csv) ou o PDF exportado direto do sistema.']);
        }

        // Linha de títulos: a que mais casa com os nomes de coluna conhecidos (pelo menos 2).
        $best = null;
        $bestHits = 1;
        foreach (array_slice($lines, 0, 80, true) as $i => $line) {
            $hits = count(array_filter($line['chunks'], fn ($c) => in_array(Sheet::key($c['text']), $known, true)));
            if ($hits > $bestHits) {
                [$best, $bestHits] = [$i, $hits];
            }
        }
        if ($best === null) {
            throw ValidationException::withMessages(['file' => 'Não achamos a linha de títulos da tabela neste PDF (ex.: Produto, Cor, Tamanho, Quantidade, Preço). Confira se é um relatório em forma de tabela, ou envie a planilha.']);
        }

        $header = $lines[$best]['chunks'];
        $titles = array_map(fn ($c) => $c['text'], $header);
        $headerKey = implode('|', $titles);
        $data = array_values(array_filter(array_slice($lines, $best + 1), fn ($l) => implode('|', array_map(fn ($c) => $c['text'], $l['chunks'])) !== $headerKey));
        $bounds = self::bounds($header, $data);
        $columns = count($titles);

        // Cada linha do PDF vira células; linhas com um texto só ficam para juntar depois.
        $rows = [];
        $loose = [];
        foreach ($data as $line) {
            $cells = array_fill(0, $columns, '');
            $last = [];
            foreach ($line['chunks'] as $chunk) {
                $col = self::column($bounds, $chunk['x']);
                // Mesma célula, colado no pedaço anterior: é a mesma palavra (sem espaço).
                $glued = isset($last[$col]) && mb_strlen($last[$col]['text']) * self::$letter * 1.1 + 0.5 >= $chunk['x'] - $last[$col]['x'];
                $cells[$col] = trim($cells[$col].($glued ? '' : ' ').$chunk['text']);
                $last[$col] = $chunk;
            }
            $filled = count(array_filter($cells, fn ($c) => $c !== ''));
            if ($filled >= 2) {
                $rows[] = ['cells' => $cells, 'page' => $line['page'], 'y' => $line['y'], 'above' => [], 'below' => []];
            } elseif ($filled === 1) {
                // Um número sozinho pode ser o fim de uma descrição que quebrou ("SUTIÃ RENDA NUDE |" + "42"):
                // decide depois, conforme a linha vizinha.
                $loose[] = ['cells' => $cells, 'page' => $line['page'], 'y' => $line['y'], 'numeric' => self::looksLikeRow($cells)];
            }
        }
        if (count($rows) > Sheet::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'O PDF passa de '.Sheet::MAX_ROWS.' linhas. Divida em partes e envie uma de cada vez.']);
        }

        // Texto que quebrou de linha: vai para a linha da tabela mais perto na mesma página (acima ou abaixo).
        $gap = self::lineGap($rows);
        $numbers = [];
        foreach ($loose as $piece) {
            $target = null;
            foreach ($rows as $r => $row) {
                $distance = abs($row['y'] - $piece['y']);
                if ($row['page'] === $piece['page'] && $distance <= $gap * 0.8 && ($target === null || $distance < abs($rows[$target]['y'] - $piece['y']))) {
                    $target = $r;
                }
            }
            // O número só continua a célula vizinha se ela for texto (descrição); ao lado de outro número
            // (ex.: "Total: 120" embaixo da quantidade) é uma linha à parte, como antes.
            $col = array_key_first(array_filter($piece['cells'], fn ($c) => $c !== ''));
            if ($piece['numeric'] && ($target === null || ! preg_match('/\p{L}/u', $rows[$target]['cells'][$col]))) {
                $numbers[] = ['cells' => $piece['cells'], 'page' => $piece['page'], 'y' => $piece['y'], 'above' => [], 'below' => []];
            } elseif ($target !== null) {
                $rows[$target][$piece['y'] > $rows[$target]['y'] ? 'above' : 'below'][] = $piece;
            }
        }
        if ($numbers !== []) {
            $rows = array_merge($rows, $numbers);
            usort($rows, fn ($a, $b) => [$a['page'], $b['y']] <=> [$b['page'], $a['y']]); // de cima para baixo, página a página
        }

        $grid = [$titles];
        foreach ($rows as $row) {
            $cells = $row['cells'];
            usort($row['above'], fn ($a, $b) => $a['y'] <=> $b['y']); // o mais perto da linha por último
            foreach (array_reverse($row['above']) as $piece) {
                foreach ($piece['cells'] as $c => $text) {
                    $cells[$c] = $text !== '' ? trim($text.' '.$cells[$c]) : $cells[$c];
                }
            }
            usort($row['below'], fn ($a, $b) => $b['y'] <=> $a['y']);
            foreach ($row['below'] as $piece) {
                foreach ($piece['cells'] as $c => $text) {
                    $cells[$c] = $text !== '' ? trim($cells[$c].' '.$text) : $cells[$c];
                }
            }
            $grid[] = $cells;
        }

        return $grid;
    }

    /**
     * O texto do PDF na ordem em que se lê na página (de cima para baixo, da esquerda para a direita), uma
     * linha por altura. Há PDFs que guardam o texto fora dessa ordem (o comprovante da Miza grava a
     * referência e o código de barras depois do total), e a leitura "corrida" sai embaralhada.
     */
    public static function text(string $path): string
    {
        return implode("\n", array_map(fn ($line) => implode('  ', array_column($line['chunks'], 'text')), self::read($path)));
    }

    /** @return array<int, array{y: float, page: int, chunks: array<int, array{x: float, text: string}>}> */
    private static function read(string $path): array
    {
        try {
            $pdf = (new Parser)->parseFile($path);
            $lines = [];
            foreach (array_slice($pdf->getPages(), 0, self::MAX_PAGES) as $p => $page) {
                foreach (self::lines($page->getDataTm()) as $line) {
                    $lines[] = $line + ['page' => $p];
                }
            }

            return $lines;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'Não conseguimos abrir este PDF. Se ele tem senha, salve uma cópia sem senha, ou envie a planilha (.xlsx ou .csv).']);
        }
    }

    /**
     * Pedaços de texto da página agrupados por altura (de cima para baixo), da esquerda para a direita.
     *
     * @return array<int, array{y: float, chunks: array<int, array{x: float, text: string}>}>
     */
    private static function lines(array $data): array
    {
        $chunks = [];
        foreach ($data as [$tm, $text]) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
            if ($text !== '') {
                $chunks[] = ['x' => (float) $tm[4], 'y' => (float) $tm[5], 'text' => $text];
            }
        }
        usort($chunks, fn ($a, $b) => [$b['y'], $a['x']] <=> [$a['y'], $b['x']]);

        $lines = [];
        foreach ($chunks as $chunk) {
            $last = array_key_last($lines);
            if ($last !== null && abs($lines[$last]['y'] - $chunk['y']) <= 2.5) {
                $lines[$last]['chunks'][] = $chunk;
            } else {
                $lines[] = ['y' => $chunk['y'], 'chunks' => [$chunk]];
            }
        }

        // Largura de uma letra, pela distância entre linhas (≈ 1,5 × o tamanho da letra).
        $gaps = [];
        for ($i = 1; $i < count($lines); $i++) {
            $gaps[] = abs($lines[$i - 1]['y'] - $lines[$i]['y']);
        }
        sort($gaps);
        $letter = $gaps ? max(3.0, min(12.0, $gaps[intdiv(count($gaps), 2)] * 0.4)) : 5.0;

        foreach ($lines as &$line) {
            usort($line['chunks'], fn ($a, $b) => $a['x'] <=> $b['x']);
            // Pedaço curto (até 2 letras) colado num que começa com minúscula é a mesma palavra que o PDF partiu
            // no ajuste entre letras ("T" + "amanho", "V" + "inho").
            $merged = [];
            foreach ($line['chunks'] as $c) {
                $prev = array_key_last($merged);
                if ($prev !== null && mb_strlen($merged[$prev]['last']) <= 2 && preg_match('/^\p{Ll}/u', $c['text']) && mb_strlen($merged[$prev]['last']) * $letter + $letter * 0.6 >= $c['x'] - $merged[$prev]['lastX']) {
                    $merged[$prev]['text'] .= $c['text'];
                    $merged[$prev]['last'] = $c['text'];
                    $merged[$prev]['lastX'] = $c['x'];
                } else {
                    $merged[] = ['x' => $c['x'], 'text' => $c['text'], 'last' => $c['text'], 'lastX' => $c['x']];
                }
            }
            $line['chunks'] = array_map(fn ($c) => ['x' => $c['x'], 'text' => $c['text']], $merged);
        }

        return $lines;
    }

    /**
     * Onde cada coluna termina (limite entre ela e a próxima). Aprende pelas linhas com um pedaço por
     * coluna; sem elas, usa o meio entre os títulos.
     *
     * @return float[]
     */
    private static function bounds(array $header, array $data): array
    {
        $n = count($header);
        $full = array_values(array_filter($data, fn ($l) => count($l['chunks']) === $n));
        if (count($full) >= 3) {
            $min = $max = [];
            foreach ($full as $line) {
                foreach ($line['chunks'] as $i => $c) {
                    $min[$i] = min($min[$i] ?? INF, $c['x']);
                    $max[$i] = max($max[$i] ?? -INF, $c['x']);
                }
            }
            $bounds = [];
            for ($i = 0; $i < $n - 1; $i++) {
                // O que começa antes da próxima coluna é desta (ex.: o fim de um código que o PDF partiu).
                $bounds[] = $max[$i] < $min[$i + 1] ? $min[$i + 1] - 0.5 : ($min[$i] + $min[$i + 1]) / 2;
            }

            return $bounds;
        }

        $bounds = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $bounds[] = ($header[$i]['x'] + $header[$i + 1]['x']) / 2;
        }

        return $bounds;
    }

    /**
     * Menor distância por letra entre dois pedaços vizinhos na mesma linha. Onde o PDF partiu uma palavra, a
     * distância é exatamente a largura do pedaço; entre colunas é sempre maior.
     */
    private static function letterWidth(array $lines): float
    {
        $best = INF;
        foreach ($lines as $line) {
            for ($i = 1; $i < count($line['chunks']); $i++) {
                $prev = $line['chunks'][$i - 1];
                if (mb_strlen($prev['text']) >= 3 && mb_strlen($prev['text']) <= 8) { // trecho longo de minúsculas engana (letras mais estreitas)
                    $best = min($best, ($line['chunks'][$i]['x'] - $prev['x']) / mb_strlen($prev['text']));
                }
            }
        }

        return is_finite($best) && $best > 0 ? $best : 5.0;
    }

    private static function column(array $bounds, float $x): int
    {
        foreach ($bounds as $i => $limit) {
            if ($x < $limit) {
                return $i;
            }
        }

        return count($bounds);
    }

    /** Distância típica entre as linhas da tabela. */
    private static function lineGap(array $rows): float
    {
        $gaps = [];
        for ($i = 1; $i < count($rows); $i++) {
            if ($rows[$i]['page'] === $rows[$i - 1]['page']) {
                $gaps[] = abs($rows[$i - 1]['y'] - $rows[$i]['y']);
            }
        }
        sort($gaps);

        return $gaps ? max(6.0, $gaps[intdiv(count($gaps), 2)]) : 14.0;
    }

    /** Linha com número ou valor em dinheiro é linha da tabela, não pedaço de texto que quebrou. */
    private static function looksLikeRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== '' && preg_match('/^(R\$\s*)?-?[\d.,]+$/u', $c)) {
                return true;
            }
        }

        return false;
    }
}
