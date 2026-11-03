<?php

namespace App\Platform\Messaging;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Envia e-mail simples da Foxy (comunicados): texto da equipe em HTML enxuto, com o link para sair da lista.
 */
class EmailSender
{
    /**
     * @return string|null null = enviado; texto = motivo da falha
     */
    public function send(string $to, string $subject, string $text, ?string $unsubscribeUrl = null): ?string
    {
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'E-mail inválido.';
        }

        $html = view('mail.announcement', ['text' => $text, 'unsubscribeUrl' => $unsubscribeUrl])->render();

        try {
            Mail::html($html, function (Message $message) use ($to, $subject, $unsubscribeUrl) {
                $message->to($to)->subject($subject);
                if ($unsubscribeUrl) {
                    $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$unsubscribeUrl.'>');
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Comunicado: e-mail não saiu', ['error' => $e->getMessage()]);

            return 'O servidor de e-mail recusou.';
        }

        return null;
    }
}
