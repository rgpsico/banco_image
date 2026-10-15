<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Para o COMPRADOR: pedido reembolsado; os downloads foram encerrados. */
class OrderRefunded extends MarketplaceNotification
{
    public function __construct(public string $orderUlid, public int $totalCents)
    {
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Seu pedido foi reembolsado')
            ->greeting('Olá, ' . explode(' ', trim($notifiable->name))[0] . '.')
            ->line('O pedido ' . $this->orderUlid . ' foi reembolsado (' . self::brl($this->totalCents) . ').')
            ->line('O prazo para o valor aparecer depende da forma de pagamento. Os downloads desse pedido foram encerrados.');
    }
}
