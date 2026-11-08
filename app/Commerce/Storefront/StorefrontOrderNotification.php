<?php

namespace App\Commerce\Storefront;

use App\Commerce\Catalog\Money;
use App\Commerce\Orders\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** E-mail do pedido feito na loja própria: para o lojista (pedido novo) e para o comprador (confirmação). */
class StorefrontOrderNotification extends Notification
{
    public function __construct(public string $storeName, public Order $order, public string $url, public bool $forOwner) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = Money::format($this->order->total_cents);

        return $this->forOwner
            ? (new MailMessage)->subject("Pedido #{$this->order->number} na loja própria — {$total}")
                ->greeting('Pedido novo!')
                ->line("Chegou o pedido #{$this->order->number} pela loja própria da {$this->storeName}, de {$total}.")
                ->line('As peças estão reservadas por 48 h. Confira o Pix na sua conta e confirme o pagamento no pedido.')
                ->action('Abrir o pedido', $this->url)
                ->salutation('Foxy Commerce')
            : (new MailMessage)->subject("Seu pedido #{$this->order->number} na {$this->storeName}")
                ->greeting('Pedido recebido!')
                ->line("Recebemos o seu pedido #{$this->order->number}, de {$total}.")
                ->line('Para confirmar, pague pelo Pix que aparece na página do pedido. As peças ficam reservadas por 48 horas.')
                ->action('Ver o pedido e pagar', $this->url)
                ->salutation($this->storeName);
    }
}
