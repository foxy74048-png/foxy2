<?php

namespace App\Finance\Console;

use App\Finance\WhatsApp\PhoneLinks;
use App\Platform\Messaging\WhatsAppConnections;
use Illuminate\Console\Command;

/**
 * Liga o número do Foxy Finanças (instância na Evolution) à entrada de mensagens da Foxy e mostra a situação.
 * Com --conectar, cria a instância e grava o QR Code num arquivo para escanear com o celular do número
 * (passo do responsável: o aparelho com o chip do Foxy Finanças).
 */
class SetUpWhatsApp extends Command
{
    protected $signature = 'financas:whatsapp
        {--url= : endereço público da Foxy (padrão: https://financas.foxy.net.br)}
        {--conectar : cria a instância e grava o QR Code para escanear}
        {--qr= : onde gravar o QR Code (padrão: storage/app/private/financas-whatsapp-qr.png)}';

    protected $description = 'Aponta as mensagens recebidas pelo número do Foxy Finanças para a Foxy e mostra se o número está conectado';

    public function handle(WhatsAppConnections $whatsapp): int
    {
        $token = (string) config('foxy.messaging.whatsapp.webhook_token');
        if (! $whatsapp->isConfigured() || $token === '') {
            $this->error('Falta configurar FOXY_WHATSAPP_API_URL, FOXY_WHATSAPP_API_KEY e FOXY_WHATSAPP_WEBHOOK_TOKEN no .env.');

            return self::FAILURE;
        }

        $instance = PhoneLinks::instance();

        if ($this->option('conectar')) {
            $result = $whatsapp->connect($instance);
            if (! empty($result['error'])) {
                $this->error($result['error']);

                return self::FAILURE;
            }
            if (! empty($result['qr'])) {
                $path = (string) ($this->option('qr') ?: storage_path('app/private/financas-whatsapp-qr.png'));
                $png = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $result['qr']), true);
                if ($png === false || @file_put_contents($path, $png) === false) {
                    $this->error("Não deu para gravar o QR Code em {$path}.");

                    return self::FAILURE;
                }
                $this->info("QR Code gravado em {$path}. Escaneie em até 1 minuto: WhatsApp do celular → Aparelhos conectados → Conectar.");
            } else {
                $this->info("A instância {$instance} já está conectada.");
            }
        }

        $base = rtrim((string) ($this->option('url') ?: 'https://'.config('foxy.hosts.financas')), '/');
        if ($error = $whatsapp->setWebhook($instance, $base.'/webhooks/whatsapp/'.$token)) {
            $this->error("Não deu para ligar a entrada na instância {$instance}: {$error}");

            return self::FAILURE;
        }

        $state = $whatsapp->state($instance)['status'];
        $this->info("Entrada ligada na instância {$instance}. Situação do número: {$state}.");
        if ($state !== 'open') {
            $this->warn('O número ainda não está conectado: rode de novo com --conectar e escaneie o QR Code.');
        }

        return self::SUCCESS;
    }
}
