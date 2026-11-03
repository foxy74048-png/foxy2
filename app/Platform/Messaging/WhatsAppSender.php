<?php

namespace App\Platform\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envia WhatsApp pelo número da Foxy (a mesma Evolution API que o Foxy Studio usa; instância do número da Foxy).
 * Desliga sozinho sem configuração (FOXY_WHATSAPP_API_URL / _API_KEY / _INSTANCE no .env).
 */
class WhatsAppSender
{
    public function isConfigured(): bool
    {
        $c = config('foxy.messaging.whatsapp');

        return filled($c['api_url'] ?? null) && filled($c['api_key'] ?? null) && filled($c['instance'] ?? null);
    }

    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return $digits === '' ? '' : (str_starts_with($digits, '55') ? $digits : '55'.$digits);
    }

    /**
     * @return string|null null = enviado; texto = motivo da falha
     */
    public function send(string $phone, string $text): ?string
    {
        if (! $this->isConfigured()) {
            return 'WhatsApp da Foxy não configurado.';
        }

        $number = self::normalize($phone);
        if (strlen($number) < 12) {
            return 'Número de WhatsApp inválido.';
        }

        $c = config('foxy.messaging.whatsapp');

        try {
            $response = Http::withHeaders(['apikey' => $c['api_key'], 'Authorization' => 'Bearer '.$c['api_key']])
                ->timeout(15)->asJson()
                ->post(rtrim($c['api_url'], '/').'/message/sendText/'.$c['instance'], [
                    'number' => $number, 'text' => $text, 'delay' => 1200, 'presence' => 'composing', 'linkPreview' => false,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Comunicado: WhatsApp não saiu', ['error' => $e->getMessage()]);

            return 'Sem resposta do WhatsApp.';
        }

        return $response->successful() ? null : 'WhatsApp recusou ('.$response->status().').';
    }
}
