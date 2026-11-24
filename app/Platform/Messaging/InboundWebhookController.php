<?php

namespace App\Platform\Messaging;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Entrada de WhatsApp da Evolution API: POST /webhooks/whatsapp/{token}. Confere o token secreto do endereço,
 * aceita só instâncias com quem atende (config foxy.messaging.inbound), ignora mensagem repetida e responde na hora;
 * o atendimento (que pode chamar a IA) roda depois da resposta, para a Evolution não esperar.
 */
class InboundWebhookController
{
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $expected = (string) config('foxy.messaging.whatsapp.webhook_token');
        abort_if($expected === '' || ! hash_equals($expected, $token), 404);

        $message = InboundMessage::fromEvolution($request->all());
        $handler = $message ? config('foxy.messaging.inbound.'.$message->instance) : null;
        if (! $message || ! $handler) {
            return response()->json(['ok' => true, 'ignored' => true]);
        }

        try {
            DB::table('platform_inbound_messages')->insert([
                'id' => (string) Str::ulid(), 'instance' => $message->instance, 'message_id' => $message->messageId, 'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        // Os "terminating" ficam guardados na aplicação: num processo que atende várias requisições (testes,
        // worker), o desta mensagem rodaria de novo na próxima. Roda uma vez só.
        $done = false;
        app()->terminating(function () use ($handler, $message, &$done) {
            if ($done) {
                return;
            }
            $done = true;
            try {
                app($handler)->handle($message);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return response()->json(['ok' => true]);
    }
}
