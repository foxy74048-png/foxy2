<?php

namespace App\Commerce\Supply;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Lê o XML da nota fiscal eletrônica (NF-e) da fornecedora: o formato é o mesmo para todas, então serve para
 * qualquer uma. Cada item traz código do fornecedor, descrição, código de barras, quantidade e valor; o
 * valor é o custo (com o desconto do item). CFOP x917 = remessa em consignação; senão é compra. As
 * duplicatas viram as parcelas da conta a pagar.
 *
 * Devolve o mesmo formato do ReceiptParser::parse, mais document (CNPJ), source, consigned e installments.
 */
class NfeParser
{
    public static function looksLike(string $text): bool
    {
        return (bool) preg_match('/<(\w+:)?(infNFe|nfeProc)\b/', $text);
    }

    public static function parse(string $xml): array
    {
        // Nota não tem DOCTYPE/entidade: recusar evita XML malicioso (entidades externas, expansão).
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $xml)) {
            throw ValidationException::withMessages(['file' => 'Este XML não parece uma nota fiscal.']);
        }
        $xml = preg_replace(['/\sxmlns(:\w+)?="[^"]*"/', '/<(\/?)\w+:/'], ['', '<$1'], $xml) ?? '';
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $inf = $doc ? ($doc->xpath('//infNFe')[0] ?? null) : null;
        if (! $inf) {
            throw ValidationException::withMessages(['file' => 'Não encontramos uma nota fiscal (NF-e) neste XML. Envie o XML da nota que a fornecedora mandou.']);
        }

        $items = [];
        $consigned = false;
        foreach ($inf->xpath('det/prod') as $p) {
            $qty = (float) $p->qCom;
            $quantity = (int) round($qty);
            if ($quantity <= 0) {
                continue;
            }
            $consigned = $consigned || substr((string) $p->CFOP, 1) === '917';
            $net = (float) $p->vProd - (float) $p->vDesc;
            $items[] = [
                'ref' => null,
                'code' => Str::limit(trim((string) $p->cProd), 60, '') ?: null,
                'description' => Str::limit(Str::squish((string) $p->xProd), 200, ''),
                'barcode' => self::barcode((string) $p->cEAN) ?? self::barcode((string) $p->cEANTrib),
                'brand' => null,
                'price_cents' => (int) round($net / $qty * 100),
                'quantity' => $quantity,
            ];
        }

        $installments = [];
        foreach ($inf->xpath('cobr/dup') as $dup) {
            $due = self::date((string) $dup->dVenc);
            $cents = (int) round((float) $dup->vDup * 100);
            if ($due && $cents > 0) {
                $installments[] = ['due' => $due, 'cents' => $cents];
            }
        }
        usort($installments, fn ($a, $b) => strcmp($a['due'], $b['due']));

        $name = self::text($inf, 'emit/xFant') ?: self::text($inf, 'emit/xNome');

        return [
            'source' => 'nfe',
            'supplier' => $name !== '' ? Str::title(Str::lower(Str::squish($name))) : null,
            'document' => self::text($inf, 'emit/CNPJ') ?: (self::text($inf, 'emit/CPF') ?: null),
            'number' => self::text($inf, 'ide/nNF') ?: null,
            'date' => self::date(self::text($inf, 'ide/dhEmi')) ?? self::date(self::text($inf, 'ide/dEmi')),
            'closing' => $installments[0]['due'] ?? null,
            'total_cents' => (int) round((float) self::text($inf, 'total/ICMSTot/vNF') * 100) ?: null,
            'consigned' => $consigned,
            'installments' => $installments,
            'items' => $items,
        ];
    }

    /** Texto de um campo pelo caminho (vazio se a nota não tiver). */
    private static function text(\SimpleXMLElement $node, string $path): string
    {
        return trim((string) ($node->xpath($path)[0] ?? ''));
    }

    private static function barcode(string $value): ?string
    {
        $value = trim($value);

        return preg_match('/^\d{8,14}$/', $value) ? $value : null; // "SEM GTIN" e vazio não contam
    }

    private static function date(string $value): ?string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) && checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4)) ? $m[1] : null;
    }
}
