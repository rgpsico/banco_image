<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

/** Para o VENDEDOR: uma venda foi paga. O comprador aparece só pelo primeiro nome. */
class SaleMade extends MarketplaceNotification
{
    public function __construct(public string $title, public int $earningCents, public string $buyerFirstName)
    {
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Você fez uma venda no MediaHub')
            ->greeting('Boa notícia, ' . explode(' ', trim($notifiable->name))[0] . '!')
            ->line("\"{$this->title}\" foi comprada por {$this->buyerFirstName}.")
            ->line('Seu ganho (já descontada a comissão da plataforma): ' . self::brl($this->earningCents) . '.')
            ->action('Ver minhas vendas', self::url('/seller/sales'));
    }
}
