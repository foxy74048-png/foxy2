<?php

namespace App\Commerce\Fiscal;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Lê o XML de uma NF-e (nfeProc ou NFe) e a chave de acesso. Sem rede e sem entidades externas.
 */
class NfeXml
{
    /** Chave de 44 dígitos com dígito verificador (módulo 11) correto. */
    public static function validKey(string $key): bool
    {
        return (bool) preg_match('/^\d{44}$/', $key) && (int) $key[43] === self::checkDigit(substr($key, 0, 43));
    }

    /** Dígito verificador (módulo 11, pesos 2 a 9 da direita para a esquerda) dos 43 primeiros números. */
    public static function checkDigit(string $first43): int
    {
        $sum = 0;
        $weight = 2;
        for ($i = strlen($first43) - 1; $i >= 0; $i--) {
            $sum += (int) $first43[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }
        $digit = 11 - ($sum % 11);

        return $digit >= 10 ? 0 : $digit;
    }

    public static function normalizeKey(?string $key): string
    {
        return preg_replace('/\D/', '', (string) $key);
    }

    /**
     * @return array{access_key: string, number: string, series: string, issued_at: ?Carbon, total_cents: ?int}
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_use_internal_errors($previous);

        $inf = $doc ? ($doc->xpath('//*[local-name()="infNFe"]')[0] ?? null) : null;
        if (! $inf) {
            throw ValidationException::withMessages(['xml' => 'Este arquivo não parece o XML de uma NF-e.']);
        }

        $key = self::normalizeKey((string) $inf['Id']);
        if (! self::validKey($key)) {
            throw ValidationException::withMessages(['xml' => 'A chave de acesso do XML é inválida.']);
        }

        $value = fn (string $tag) => (string) ($inf->xpath('.//*[local-name()="'.$tag.'"]')[0] ?? '');
        $issued = $value('dhEmi') ?: $value('dEmi');
        $total = $value('vNF');

        return [
            'access_key' => $key,
            'number' => $value('nNF'),
            'series' => $value('serie'),
            'issued_at' => $issued ? Carbon::parse($issued) : null,
            'total_cents' => $total !== '' ? (int) round((float) $total * 100) : null,
        ];
    }
}
