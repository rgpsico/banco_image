<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;

/** Para o COMPRADOR: pagamento confirmado, downloads liberados. */
class PurchaseConfirmed extends MarketplaceNotification
{
    public function __construct(public string $orderUlid, public int $totalCents, public int $itemCount)
    {
    }

    public static function for(Order $order): self
    {
        return new self($order->ulid, $order->total_cents, $order->items->count());
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pagamento confirmado - suas fotos estão liberadas')
            ->greeting('Olá, ' . explode(' ', trim($notifiable->name))[0] . '!')
            ->line("Recebemos o pagamento de " . self::brl($this->totalCents) . " ({$this->itemCount} item(ns)).")
            ->line('Os arquivos originais já estão disponíveis na sua área de compras.')
            ->action('Ver minhas compras', self::url('/purchases'))
            ->line('Guarde este e-mail: o código do pedido é ' . $this->orderUlid . '.');
    }
}
