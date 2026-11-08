<?php

namespace App\Commerce\Storefront;

use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Str;

/**
 * Pix "copia e cola" estático com valor (padrão BR Code do Banco Central, EMV). Gerado aqui, sem gateway:
 * o dinheiro vai direto para a chave do lojista. A Foxy não recebe nem confirma pagamentos (D-C4).
 */
class PixCode
{
    public static function payload(string $key, string $name, string $city, int $amountCents, string $reference): string
    {
        $field = fn (string $id, string $value) => $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        $clean = fn (string $text, int $max) => mb_substr(trim(preg_replace('/[^A-Za-z0-9 ]/', '', Str::ascii($text))), 0, $max) ?: 'X';

        $payload = $field('00', '01')
            .$field('26', $field('00', 'br.gov.bcb.pix').$field('01', trim($key)))
            .$field('52', '0000')
            .$field('53', '986')
            .$field('54', number_format($amountCents / 100, 2, '.', ''))
            .$field('58', 'BR')
            .$field('59', Str::upper($clean($name, 25)))
            .$field('60', Str::upper($clean($city, 15)))
            .$field('62', $field('05', substr(preg_replace('/[^A-Za-z0-9]/', '', $reference), 0, 25) ?: '***'))
            .'6304';

        return $payload.self::crc16($payload);
    }

    /** CRC16-CCITT (polinômio 0x1021, início 0xFFFF), em 4 dígitos hexadecimais maiúsculos. */
    public static function crc16(string $data): string
    {
        $crc = 0xFFFF;
        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    /** QR em SVG (data URI) para mostrar na página do pedido. */
    public static function qrSvg(string $payload): string
    {
        return (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'addQuietzone' => true])))->render($payload);
    }
}
