<?php

namespace App\Platform\Support;

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

        // Com vírgula, a vírgula é o decimal e os pontos são milhar. Sem vírgula, ponto seguido de 3 dígitos é
        // milhar ("1.200" = mil e duzentos, "1.500.000"); senão o ponto é o decimal ("79.90").
        $normalized = match (true) {
            str_contains($text, ',') => str_replace(['.', ','], ['', '.'], $text),
            (bool) preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text) => str_replace('.', '', $text),
            default => $text,
        };

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
