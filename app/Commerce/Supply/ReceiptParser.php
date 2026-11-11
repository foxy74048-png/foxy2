<?php

namespace App\Commerce\Supply;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser;

/**
 * Lê comprovantes de remessa/consignação de fornecedor (ex.: Miza) a partir do PDF ou do texto colado.
 * Cada linha: [referência] descrição | tamanho, código de barras, marca, R$ preço, qtd, dev., ent., R$ total.
 * Funciona com as colunas grudadas (como o texto sai do PDF) ou separadas (texto colado). Agrupa as linhas
 * em produtos: mesma referência (ou mesmo começo do nome) = um produto; o que muda no fim do nome é a cor e
 * o que vem depois do "|" é o tamanho.
 */
class ReceiptParser
{
    private const MONEY = '\d{1,3}(?:\.\d{3})*,\d{2}';

    /** Tamanhos de etiqueta: letras (PP a EGG, G1-G4, U), numeração (36-60) e sutiã (40B, 44C). */
    private const SIZES = 'PP|P|M|G|GG|XG|XGG|XXG|EG|EGG|EXG|G[1-4]|U|UN|UNICO|ÚNICO|TU|\d{2}(?:[A-F]{1,2})?';

    public static function textFromPdf(string $path): string
    {
        return (new Parser)->parseFile($path)->getText();
    }

    /**
     * @return array{source: string, supplier: ?string, document: ?string, number: ?string, date: ?string, closing: ?string, total_cents: ?int, items: array<int, array>}
     */
    public static function parse(string $text): array
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        // Linhas começam depois do cabeçalho da tabela (se houver), para o número da venda não virar "referência".
        $start = 0;
        if (preg_match('/Descri\S*o.{0,80}?Total/iu', $flat, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1] + strlen($m[0][0]);
        }
        $body = substr($flat, $start);

        $pattern = '/(?:(?<ref>\b\d{3,6})\s+)?(?<desc>[^\d\s][^R]{2,200}?|[^\d\s].{2,200}?)\s*(?<bar>\d{8,14})\s*(?<brand>[^\d$]{0,40}?)\s*R\$\s*(?<price>'.self::MONEY.')\s*(?<qty>\d+(?:[.,]\d+)?)\s*(?<dev>\d+(?:[.,]\d)?)\s*(?<ent>\d+(?:[.,]\d)?)\s*R\$\s*(?<total>'.self::MONEY.')/u';
        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        $items = [];
        foreach ($matches as $m) {
            $qty = (int) round((float) str_replace(',', '.', $m['ent'] !== '' ? $m['ent'] : $m['qty']));
            if ($qty <= 0) {
                continue; // tudo devolvido no próprio comprovante
            }
            $items[] = [
                'ref' => $m['ref'] !== '' ? $m['ref'] : null,
                'description' => Str::squish($m['desc']),
                'barcode' => $m['bar'],
                'brand' => Str::squish($m['brand']) ?: null,
                'price_cents' => self::cents($m['price']),
                'quantity' => $qty,
            ];
        }

        $date = fn (string $label) => preg_match('/'.$label.'\s*:?\s*(\d{2}\/\d{2}\/\d{4})/iu', $flat, $d) ? Carbon::createFromFormat('d/m/Y', $d[1])->toDateString() : null;
        $supplier = preg_match('/Empresa\s*:\s*(?:\([A-Z]\)\s*)?(.+?)(?:\s+(?:LTDA|EIRELI|ME|S\/?A)\b|\s*\(|\s+CPF|\s+Num\.)/iu', $flat, $s) ? Str::title(Str::squish($s[1])) : null;

        return [
            'source' => 'comprovante',
            'document' => preg_match('/CNPJ\s*:?\s*(\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2})/u', $flat, $c) ? preg_replace('/\D/', '', $c[1]) : null,
            'supplier' => $supplier ?? (collect($items)->pluck('brand')->filter()->first() ? Str::title(collect($items)->pluck('brand')->filter()->first()) : null),
            'number' => preg_match('/Num\.?\s*(?:venda|pedido|documento)?\s*:?\s*(\d{1,10})/iu', $flat, $n) ? $n[1] : null,
            'date' => $date('Data'),
            'closing' => $date('Fechamento') ?? $date('Vencimento'),
            'total_cents' => preg_match('/Total\s*:?\s*R\$\s*('.self::MONEY.')/u', $flat, $t) ? self::cents($t[1]) : null,
            'items' => $items,
        ];
    }

    /**
     * Agrupa as linhas em produtos com grade (Cor e/ou Tamanho).
     *
     * @return array<int, array{name: string, ref: ?string, lines: array<int, array>}>
     */
    public static function group(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            [$left, $size] = str_contains($item['description'], '|')
                ? array_map('trim', [Str::beforeLast($item['description'], '|'), Str::afterLast($item['description'], '|')])
                : self::splitSize($item['description']);
            // Sem referência: as primeiras palavras do nome, deixando de fora a última (quase sempre a cor).
            $words = explode(' ', Str::squish($left));
            $key = $item['ref'] ?? Str::upper(implode(' ', array_slice($words, 0, max(2, min(3, count($words) - 1)))));
            $groups[$key]['ref'] = $item['ref'];
            $groups[$key]['lines'][] = $item + ['left' => $left, 'size' => $size];
        }

        return array_values(array_map(function (array $group) {
            $lefts = array_map(fn ($l) => explode(' ', $l['left']), $group['lines']);
            $prefix = $lefts[0];
            foreach ($lefts as $words) {
                $i = 0;
                while ($i < count($prefix) && $i < count($words) && Str::upper($prefix[$i]) === Str::upper($words[$i])) {
                    $i++;
                }
                $prefix = array_slice($prefix, 0, $i);
            }
            $distinct = count(array_unique(array_map(fn ($l) => Str::upper($l['left']), $group['lines']))) > 1;
            if (! $distinct || $prefix === []) {
                $prefix = $lefts[0];
            }
            $name = implode(' ', $prefix);

            return [
                'name' => Str::ucfirst(Str::lower($name)),
                'ref' => $group['ref'],
                'lines' => array_map(fn ($l) => [
                    'description' => $l['description'], 'barcode' => $l['barcode'], 'code' => $l['code'] ?? null, 'price_cents' => $l['price_cents'], 'quantity' => $l['quantity'],
                    'color' => $distinct ? Str::title(Str::lower(trim(Str::after(Str::upper($l['left']), Str::upper($name))))) : '',
                    'size' => Str::upper($l['size']),
                ], $group['lines']),
            ];
        }, $groups));
    }

    /**
     * Tamanho quando a descrição não tem "|": "... TAM M ...", "... TAMANHO: 44" ou a última palavra ("... PRETO M",
     * "... PRETO-GG", "... /42").
     *
     * @return array{0: string, 1: string}
     */
    public static function splitSize(string $description): array
    {
        $description = Str::squish($description);
        if (preg_match('/^(.*?)[\s\-\/]*\bTAM(?:ANHO)?\.?\s*:?\s*('.self::SIZES.')\b(.*)$/iu', $description, $m)) {
            return [Str::squish($m[1].' '.$m[3]), Str::upper($m[2])];
        }
        if (preg_match('/^(.+?)[\s\-\/]+('.self::SIZES.')$/iu', $description, $m)) {
            return [trim($m[1], ' -/'), Str::upper($m[2])];
        }

        return [$description, ''];
    }

    private static function cents(string $value): int
    {
        return (int) round((float) str_replace(['.', ','], ['', '.'], $value) * 100);
    }
}
