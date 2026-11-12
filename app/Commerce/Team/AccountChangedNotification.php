<?php

namespace App\Commerce\Team;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso de segurança: o e-mail (vai para o endereço antigo) ou a senha da conta foi trocado. */
class AccountChangedNotification extends Notification
{
    public function __construct(public string $what, public ?string $newEmail = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Olá!');

        if ($this->what === 'email') {
            $mail->subject('O e-mail da sua conta Foxy foi trocado')
                ->line('O e-mail de acesso da sua conta Foxy foi trocado para '.$this->newEmail.'.');
        } else {
            $mail->subject('A senha da sua conta Foxy foi trocada')
                ->line('A senha da sua conta Foxy acabou de ser trocada. As outras sessões abertas foram encerradas.');
        }

        return $mail->line('Se foi você, não precisa fazer nada. Se não foi, responda este e-mail ou abra um chamado no suporte agora.')
            ->salutation('Equipe Foxy');
    }
}
