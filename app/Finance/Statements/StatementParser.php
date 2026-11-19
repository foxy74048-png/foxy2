<?php

namespace App\Finance\Statements;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Lê extratos em OFX (padrão de banco/conta digital/fatura) e converte as
 * linhas de planilha (CSV/Excel/PDF, lidas pela Platform\Imports\RawTable) com
 * as colunas indicadas pelo usuário.
 *
 * Copiado do Foxy Studio (Services/Statements/StatementParser) sem mudar a
 * leitura (docs/12, A-F2); os testes de lá vieram junto (StatementImportTest).
 *
 * Cada linha vira ['date' => Y-m-d, 'description' => string, 'amount' =>
 * float (positivo = entrada, negativo = saída), 'external_id' => ?string].
 */
class StatementParser
{
    /**
     * Linhas da última leitura de planilha que tinham data mas cujo valor não
     * deu para ler — para avisar na tela em vez de sumirem caladas.
     *
     * @var array<int, string>
     */
    public array $skipped = [];

    /**
     * @return array{is_credit_card: bool, account_id: ?string, rows: array<int, array{date: string, time?: ?string, description: string, amount: float, external_id: ?string}>}
     */
    public function parseOfx(string $content): array
    {
        $content = $this->toUtf8($content);

        if (! preg_match_all('/<STMTTRN>(.*?)<\/STMTTRN>/si', $content, $blocks)) {
            throw new InvalidArgumentException('Não encontrei nenhuma transação nesse arquivo OFX.');
        }

        $rows = [];
        foreach ($blocks[1] as $block) {
            $date = $this->ofxField($block, 'DTPOSTED');
            $amount = $this->ofxField($block, 'TRNAMT');
            if ($date === null || $amount === null) {
                continue;
            }

            $name = $this->ofxField($block, 'NAME');
            $memo = $this->ofxField($block, 'MEMO');
            $description = trim(implode(' — ', array_unique(array_filter([$name, $memo]))));

            $digits = preg_replace('/\D/', '', preg_replace('/\[.*$/', '', $date));
            $rows[] = [
                'date' => Carbon::createFromFormat('Ymd', substr($digits, 0, 8))->toDateString(),
                'time' => $this->ofxTime($digits),
                'description' => $description !== '' ? $description : 'Sem descrição',
                'amount' => $this->parseAmount($amount),
                'external_id' => $this->ofxField($block, 'FITID'),
            ];
        }

        return [
            'is_credit_card' => (bool) preg_match('/<CCSTMTRS>|CREDITCARDMSGSRSV1/i', $content),
            'account_id' => $this->ofxField($content, 'ACCTID'),
            'rows' => $rows,
        ];
    }

    /**
     * Também entende extrato agrupado por dia (PicPay, Nubank em PDF...): a
     * data vem num título ("04 de setembro 2026") e as linhas só trazem a
     * hora — a linha com hora usa a data do último título. Linhas de saldo
     * nunca viram lançamento, e o nome que o PDF quebrou em duas linhas
     * ("Ana Luiza de Moraes" / "Correa Noronha") volta a ser um só.
     *
     * Finanças: $categoryColumn (opcional, planilha antiga de controle) leva o
     * texto da categoria em 'category'. Única mudança em relação ao Studio.
     *
     * @param  array<int, array<int, string>>  $table
     * @return array<int, array{date: string, time: ?string, description: string, amount: float, external_id: null, category: ?string}>
     */
    public function csvRows(array $table, int $dateColumn, int $descriptionColumn, int $amountColumn, bool $hasHeader, bool $invertSign, ?int $debitColumn = null, ?int $categoryColumn = null): array
    {
        $this->skipped = [];
        // 1ª passada: cada linha da tabela vira um lançamento ("t"), um pedaço
        // de nome solto ("d", só a coluna da descrição preenchida) ou uma
        // quebra ("b": título do dia, saldo, cabeçalho repetido...).
        $rows = [];
        $items = [];
        $dayDate = null;
        foreach (array_slice($table, $hasHeader ? 1 : 0) as $cells) {
            $dateCell = trim((string) ($cells[$dateColumn] ?? ''));
            $rawAmount = (string) ($cells[$amountColumn] ?? '');
            $description = trim($cells[$descriptionColumn] ?? '');
            // Banco que separa entrada e saída em duas colunas: valor só na de
            // saída (ou entrada zerada) é um gasto.
            $isDebit = false;
            $rawDebit = $debitColumn !== null ? trim((string) ($cells[$debitColumn] ?? '')) : '';
            if ($rawDebit !== '' && $this->amountOrZero($rawAmount) === 0.0 && $this->amountOrZero($rawDebit) !== 0.0) {
                $rawAmount = $rawDebit;
                $isDebit = true;
            }

            if ($titleDate = $this->dayTitleDate($cells)) {
                $dayDate = $titleDate;
            }

            if ($this->isBalanceRow($cells)) {
                $items[] = ['b'];

                continue;
            }

            $date = $this->parseDate($dateCell);
            $time = $this->timeFrom($dateCell);
            if ($date === null && $dayDate !== null && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $dateCell)) {
                $date = $dayDate;
            }

            if (trim($rawAmount) === '' && $description !== '' && $this->onlyHasCell($cells, $descriptionColumn)) {
                $items[] = ['d', $description];

                continue;
            }

            if ($date !== null && trim($rawAmount) !== '') {
                try {
                    $amount = $this->parseAmount($rawAmount);
                    $amount = $isDebit ? -abs($amount) : $amount;
                    $rows[] = [
                        'date' => $date,
                        'own' => $description,
                        'time' => $time,
                        'before' => [],
                        'after' => [],
                        'amount' => $invertSign ? -$amount : $amount,
                        'category' => $categoryColumn !== null ? (trim((string) ($cells[$categoryColumn] ?? '')) ?: null) : null,
                    ];
                    $items[] = ['t', array_key_last($rows)];

                    continue;
                } catch (InvalidArgumentException) {
                    // Linhas de total, cabeçalhos repetidos, rodapé do PDF ("1 de 13")...
                    // Só avisa quando a célula tem cara de dinheiro e mesmo assim não deu para ler.
                    if (preg_match('/R\$|\d,\d{2}\b|\d\.\d{2}\b/u', $rawAmount)) {
                        $this->skipped[] = trim(implode(' · ', array_filter(array_map('trim', $cells), fn ($c) => $c !== '')));
                    }
                }
            }

            $items[] = ['b'];
        }

        // 2ª passada: os pedaços de nome soltos vão para o lançamento certo.
        // Quando o nome tem duas linhas, o PDF costuma pôr a hora e o valor na
        // altura do meio do nome (PicPay): a linha de cima é o começo do nome
        // do lançamento de baixo, e a de baixo, o fim do nome do de cima.
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            if ($items[$i][0] !== 'd') {
                continue;
            }
            $start = $i;
            while ($i + 1 < $count && $items[$i + 1][0] === 'd') {
                $i++;
            }
            $pieces = array_map(fn ($item) => $item[1], array_slice($items, $start, $i - $start + 1));
            $prev = $start > 0 && $items[$start - 1][0] === 't' ? $items[$start - 1][1] : null;
            $next = $i + 1 < $count && $items[$i + 1][0] === 't' ? $items[$i + 1][1] : null;
            $nextNeedsName = $next !== null && $rows[$next]['own'] === '';
            $prevNeedsName = $prev !== null && $rows[$prev]['own'] === '';

            if ($nextNeedsName && $prevNeedsName) {
                $half = (int) ceil(count($pieces) / 2);
                $rows[$prev]['after'] = array_merge($rows[$prev]['after'], array_slice($pieces, 0, $half));
                $rows[$next]['before'] = array_merge($rows[$next]['before'], array_slice($pieces, $half));
            } elseif ($nextNeedsName) {
                $rows[$next]['before'] = array_merge($rows[$next]['before'], $pieces);
            } elseif ($prev !== null) {
                $rows[$prev]['after'] = array_merge($rows[$prev]['after'], $pieces);
            }
        }

        return array_map(function (array $row) {
            $description = trim(implode(' ', array_filter([...$row['before'], $row['own'], ...$row['after']], fn ($part) => $part !== '')));

            return [
                'date' => $row['date'],
                'time' => $row['time'],
                'description' => $description !== '' ? mb_substr($description, 0, 255) : 'Sem descrição',
                'amount' => $row['amount'],
                'external_id' => null,
                'category' => $row['category'],
            ];
        }, $rows);
    }

    /** Título de dia: alguma célula é só uma data (ex.: "04 de setembro 2026"). */
    private function dayTitleDate(array $cells): ?string
    {
        foreach ($cells as $cell) {
            if (preg_match('/[a-z]/i', (string) $cell) && ($date = $this->parseDate((string) $cell))) {
                return $date;
            }
        }

        return null;
    }

    /**
     * Linha de saldo ("Saldo ao final do dia", "SALDO ANTERIOR"): alguma
     * célula começa com "saldo". Linha com hora é movimento de verdade (no
     * PicPay a forma de pagamento pode ser "Saldo").
     */
    private function isBalanceRow(array $cells): bool
    {
        $hasBalanceCell = false;
        foreach ($cells as $cell) {
            $cell = trim((string) $cell);
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $cell)) {
                return false;
            }
            $hasBalanceCell = $hasBalanceCell || (bool) preg_match('/^saldo\b/iu', $cell);
        }

        return $hasBalanceCell;
    }

    /** Valor da célula, 0 quando vazia ou ilegível (para decidir entre entrada e saída). */
    private function amountOrZero(string $raw): float
    {
        if (trim($raw) === '') {
            return 0.0;
        }
        try {
            return $this->parseAmount($raw);
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }

    private function onlyHasCell(array $cells, int $column): bool
    {
        foreach ($cells as $i => $cell) {
            if ($i !== $column && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Aceita "1.234,56", "-1234.56", "R$ -10,00", "(10,00)", "10,00-". PDF e
     * Excel costumam trocar o hífen por outro traço ("−R$ 45,90", "– 45,90")
     * e o espaço por espaço fino: sem tratar, a saída sumia da importação.
     */
    public function parseAmount(string $raw): float
    {
        $raw = str_replace(["\u{2212}", "\u{2013}", "\u{2014}", "\u{2012}", "\u{FE63}", "\u{FF0D}"], '-', $raw);
        $value = trim(str_replace(["\u{00A0}", "\u{2007}", "\u{2009}", "\u{202F}", 'R$', ' '], '', $raw));

        // Extrato em PDF costuma marcar "D" (débito) e "C" (crédito) no fim.
        $debitSuffix = (bool) preg_match('/\dD$/i', $value);
        $value = preg_replace('/(\d)[DC]$/i', '$1', $value);

        $negative = $debitSuffix || str_starts_with($value, '-') || str_ends_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $value = trim($value, '-()+');

        if ($value === '' || ! preg_match('/^[\d.,]+$/', $value)) {
            throw new InvalidArgumentException("Valor inválido: {$raw}");
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // vírgula é o separador decimal (padrão brasileiro)
            $value = str_replace(['.', ','], ['', '.'], $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        $amount = round((float) $value, 2);

        return $negative ? -$amount : $amount;
    }

    public function parseDate(string $raw): ?string
    {
        $raw = trim($raw);

        // Excel guarda data como número de dias desde 30/12/1899.
        if (preg_match('/^\d{5}(\.\d+)?$/', $raw)) {
            return Carbon::create(1899, 12, 30)->addDays((int) $raw)->toDateString();
        }

        if ($long = $this->parseLongDate($raw)) {
            return $long;
        }

        foreach (['d/m/Y', 'Y-m-d', 'd/m/y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $candidate = preg_replace('/\s.*$/', '', $raw); // descarta hora, se vier junto
            $date = \DateTime::createFromFormat('!'.$format, $candidate);
            if ($date && $date->format($format) === $candidate) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private const MONTHS = [
        'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
    ];

    /** "04 de setembro 2026", "4 de set. de 2026", "04 SET 2026". */
    private function parseLongDate(string $raw): ?string
    {
        $text = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii(trim($raw)));
        if (! preg_match('/^(\d{1,2})\s+(?:de\s+)?([a-z]{3})[a-z]*\.?\s+(?:de\s+)?(\d{4})$/', $text, $m) || ! isset(self::MONTHS[$m[2]])) {
            return null;
        }

        return checkdate(self::MONTHS[$m[2]], (int) $m[1], (int) $m[3])
            ? sprintf('%04d-%02d-%02d', $m[3], self::MONTHS[$m[2]], $m[1])
            : null;
    }

    /** Horário do OFX (AAAAMMDDHHMMSS); meia-noite exata costuma ser "sem horário". */
    private function ofxTime(string $digits): ?string
    {
        if (strlen($digits) < 12) {
            return null;
        }
        $time = substr($digits, 8, 2).':'.substr($digits, 10, 2).':'.(strlen($digits) >= 14 ? substr($digits, 12, 2) : '00');

        return $time === '00:00:00' ? null : $time;
    }

    /** Horário que veio na célula da data ("19:02", "19:02:33" ou "20/10/2026 14:30:05"). */
    private function timeFrom(string $cell): ?string
    {
        if (! preg_match('/(?:^|\s)(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($cell), $m)) {
            return null;
        }
        $time = sprintf('%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) ($m[3] ?? 0));

        return $time === '00:00:00' ? null : $time;
    }

    private function ofxField(string $block, string $tag): ?string
    {
        // OFX 1.x (SGML) não fecha as tags simples; o 2.x (XML) fecha. Pega
        // o texto até o próximo "<" ou quebra de linha, servindo aos dois.
        if (! preg_match('/<'.$tag.'>([^<\r\n]*)/i', $block, $match)) {
            return null;
        }

        $value = trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));

        return $value !== '' ? $value : null;
    }

    private function toUtf8(string $content): string
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        // Muitos bancos brasileiros ainda exportam em ISO-8859-1 / Windows-1252.
        return mb_check_encoding($content, 'UTF-8') ? $content : mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }
}
