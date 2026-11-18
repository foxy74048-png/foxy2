<?php

namespace App\Platform\Exports;

/**
 * Planilhas (CSV para o Excel). Texto que começa com = + - @ (ou tab/enter) vira fórmula no Excel: nomes vindos
 * de fora (loja pública, extrato do banco) poderiam executar algo no computador de quem abre. Esses textos saem com ' na frente.
 */
class Csv
{
    public static function cell(mixed $value): mixed
    {
        // Números (inclusive negativos, com vírgula) continuam números.
        if (! is_string($value) || $value === '' || is_numeric(str_replace(',', '.', $value))) {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** Escreve uma linha já protegida. */
    public static function row($handle, array $values): void
    {
        fputcsv($handle, array_map([self::class, 'cell'], $values), ';');
    }
}
