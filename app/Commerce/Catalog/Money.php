<?php

namespace App\Commerce\Catalog;

/**
 * Dinheiro em centavos inteiros (F6). Aceita o jeito brasileiro de digitar ("1.234,56", "79,9", "79").
 */
class Money
{
    public static function toCents(string|int|float|null $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = preg_replace('/[^\d,.\-]/', '', (string) $value);

        // Com vírgula, a vírgula é o decimal e os pontos são milhar; sem vírgula, o ponto é o decimal.
        $normalized = str_contains($text, ',') ? str_replace(['.', ','], ['', '.'], $text) : $text;

        return is_numeric($normalized) ? (int) round(((float) $normalized) * 100) : null;
    }

    public static function format(?int $cents): string
    {
        return $cents === null ? '—' : 'R$ '.number_format($cents / 100, 2, ',', '.');
    }

    /** Para preencher campos de formulário ("79,90"). */
    public static function input(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, ',', '.');
    }
}
