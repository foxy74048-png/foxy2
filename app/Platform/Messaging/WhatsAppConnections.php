<?php

namespace App\Platform\Messaging;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Número de WhatsApp de um cliente da Foxy (uma instância por organização) na mesma Evolution API do
 * Foxy Studio (FOXY_WHATSAPP_API_URL / _API_KEY). Lições de produção trazidas do Studio (App\Services\WhatsAppService):
 * - "connecting" é o handshake em andamento: não recriar, só buscar o QR de novo;
 * - depois do DELETE a Evolution ainda limpa o estado: pausa curta antes de criar;
 * - 403 "already in use" na criação = instância ainda existe: buscar o QR dela;
 * - HTTP 2xx com {"error": true} é falha de verdade;
 * - o QR pode demorar alguns segundos: tentar algumas vezes.
 */
class WhatsAppConnections
{
    public function isConfigured(): bool
    {
        $c = config('foxy.messaging.whatsapp');

        return filled($c['api_url'] ?? null) && filled($c['api_key'] ?? null);
    }

    /** @return array{status: string, message?: string} status: open, connecting, close, not_created, offline, not_configured, error */
    public function state(string $instance): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'not_configured'];
        }
        try {
            $response = $this->request('GET', "/instance/connectionState/{$instance}", timeout: 10);
        } catch (\Throwable $e) {
            return ['status' => 'offline', 'message' => $e->getMessage()];
        }
        if ($response->status() === 404) {
            return ['status' => 'not_created'];
        }
        if (! $response->successful()) {
            return ['status' => 'error', 'message' => 'HTTP '.$response->status()];
        }

        return ['status' => (string) ($response->json('instance.state') ?? $response->json('status') ?? 'unknown')];
    }

    public function isOpen(string $instance): bool
    {
        return $this->state($instance)['status'] === 'open';
    }

    /** @return array{qr?: string, open?: bool, error?: string} qr = imagem base64 para escanear */
    public function connect(string $instance): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'O WhatsApp da Foxy ainda não está configurado no servidor.'];
        }
        try {
            $state = $this->state($instance)['status'];
            if ($state === 'open') {
                return ['open' => true];
            }
            if ($state === 'connecting' && ($qr = $this->pollQr($instance))) {
                return ['qr' => $qr];
            }
            if ($state !== 'not_created') {
                rescue(fn () => $this->request('DELETE', "/instance/delete/{$instance}", timeout: 10), report: false);
                usleep($this->pause());
            }

            $response = $this->request('POST', '/instance/create', ['instanceName' => $instance, 'qrcode' => true, 'integration' => 'WHATSAPP-BAILEYS']);
            if ($response->status() === 403) {
                $response = $this->request('GET', "/instance/connect/{$instance}");
            }
            if (! $response->successful()) {
                return ['error' => 'Não deu para gerar o QR Code (HTTP '.$response->status().'). Tente de novo em instantes.'];
            }
            if (($response->json('error') ?? false) === true) {
                Log::warning('WhatsApp: Evolution respondeu error:true ao criar', ['instance' => $instance, 'body' => $response->json()]);

                return ['error' => 'O WhatsApp recusou criar a conexão. Tente de novo em instantes.'];
            }
            $qr = $response->json('qrcode.base64') ?? $response->json('base64') ?? $this->pollQr($instance);

            return $qr ? ['qr' => $qr] : ['error' => 'O QR Code demorou para ficar pronto. Clique em "Gerar QR Code" de novo.'];
        } catch (\Throwable $e) {
            return ['error' => 'Sem resposta do servidor do WhatsApp. Tente de novo em instantes.'];
        }
    }

    public function disconnect(string $instance): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            $this->request('DELETE', "/instance/logout/{$instance}", timeout: 10);
            $this->request('DELETE', "/instance/delete/{$instance}", timeout: 10);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return string|null null = enviado; texto = motivo */
    public function send(string $instance, string $phone, string $text): ?string
    {
        if (! $this->isConfigured()) {
            return 'WhatsApp não configurado.';
        }
        $number = WhatsAppSender::normalize($phone);
        if (strlen($number) < 12) {
            return 'Número de WhatsApp inválido.';
        }
        try {
            $response = $this->request('POST', "/message/sendText/{$instance}", ['number' => $number, 'text' => $text, 'delay' => 1200, 'presence' => 'composing', 'linkPreview' => false]);
        } catch (\Throwable) {
            return 'Sem resposta do WhatsApp.';
        }

        return $response->successful() ? null : 'WhatsApp recusou ('.$response->status().').';
    }

    private function pollQr(string $instance): ?string
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            usleep($this->pause() > 0 ? $attempt * 1_000_000 : 0);
            $response = rescue(fn () => $this->request('GET', "/instance/connect/{$instance}"), report: false);
            $qr = $response?->successful() ? ($response->json('base64') ?? $response->json('qrcode.base64')) : null;
            if ($qr) {
                return $qr;
            }
        }

        return null;
    }

    /** Pausa entre apagar e recriar a instância (microssegundos); zero nos testes. */
    private function pause(): int
    {
        return (int) config('foxy.messaging.whatsapp.recreate_pause_us', 1_500_000);
    }

    private function request(string $method, string $path, ?array $body = null, int $timeout = 15): Response
    {
        $c = config('foxy.messaging.whatsapp');
        $request = Http::withHeaders(['apikey' => $c['api_key'], 'Authorization' => 'Bearer '.$c['api_key'], 'Accept' => 'application/json'])
            ->timeout($timeout)->asJson();
        $url = rtrim($c['api_url'], '/').$path;

        return match ($method) {
            'GET' => $request->get($url),
            'DELETE' => $request->delete($url),
            default => $request->post($url, $body ?? []),
        };
    }
}
