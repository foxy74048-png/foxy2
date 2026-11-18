<?php

namespace App\Finance\Wallets;

use App\Finance\Access;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Convite para entrar numa carteira (o casal, a família, o sócio). */
class InvitationNotification extends Notification
{
    public function __construct(
        public string $walletName,
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
            ->subject("{$this->inviterName} convidou você para a carteira \"{$this->walletName}\" no Foxy Finanças")
            ->greeting('Olá!')
            ->line("{$this->inviterName} convidou você para acompanhar a carteira \"{$this->walletName}\" no Foxy Finanças, como ".Access::label($this->role).'.')
            ->action('Aceitar o convite', $this->url)
            ->line("O convite vale por {$this->days} dias. Se você não esperava este e-mail, é só ignorar.")
            ->salutation('Equipe Foxy');
    }
}
