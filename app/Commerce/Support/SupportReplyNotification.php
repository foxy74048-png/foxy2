<?php

namespace App\Commerce\Support;

use App\Platform\Products\ProductTicket;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A equipe Foxy respondeu um chamado da loja. */
class SupportReplyNotification extends Notification
{
    public function __construct(public ProductTicket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Resposta do suporte: '.($this->ticket->subject ?? 'seu chamado'))
            ->greeting('Olá!')
            ->line('A equipe Foxy respondeu o seu chamado "'.($this->ticket->subject ?? '').'":')
            ->line($this->ticket->admin_response)
            ->action('Ver no Foxy Commerce', route('commerce.support'))
            ->salutation('Equipe Foxy');
    }
}
