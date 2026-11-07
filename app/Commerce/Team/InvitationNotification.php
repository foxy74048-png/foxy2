<?php

namespace App\Commerce\Team;

use App\Commerce\Access;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(
        public string $storeName,
        public string $role,
        public string $inviterName,
        public string $url,
        public int $days,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Convite para a equipe da {$this->storeName} no Foxy Commerce")
            ->greeting('Olá!')
            ->line("{$this->inviterName} convidou você para trabalhar na loja {$this->storeName} no Foxy Commerce, como ".Access::label($this->role).'.')
            ->action('Aceitar o convite', $this->url)
            ->line("O convite vale por {$this->days} dias. Se você não esperava este e-mail, é só ignorar.")
            ->salutation('Equipe Foxy');
    }
}
