<?php

namespace App\Platform\Maintenance;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso da rotina de saúde para a equipe da Foxy. */
class HealthAlertNotification extends Notification
{
    public function __construct(public array $report, public bool $recovered = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->recovered ? '✅ Foxy: sistema voltou ao normal' : ($this->report['status'] === Health::FAIL ? '🔴 Foxy: problema no servidor' : '🟡 Foxy: algo pede atenção no servidor'))
            ->greeting($this->recovered ? 'Tudo certo de novo.' : 'Olá!');

        if ($this->recovered) {
            $mail->line('Todas as verificações voltaram ao normal.');
        } else {
            $mail->line('A rotina de saúde encontrou:');
            foreach ($this->report['checks'] as $check) {
                if ($check['status'] !== Health::OK) {
                    $mail->line(($check['status'] === Health::FAIL ? '🔴 ' : '🟡 ').$check['label'].': '.$check['detail']);
                }
            }
            $mail->line('Enquanto houver falha, este aviso se repete uma vez por dia.');
        }

        return $mail->action('Abrir o Foxy Admin', 'https://'.config('foxy.hosts.admin'))->salutation('Rotina de saúde da Foxy');
    }
}
