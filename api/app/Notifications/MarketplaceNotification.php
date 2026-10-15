<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/** Base dos avisos do marketplace: sempre por e-mail, sempre na fila, só depois do commit da transação. */
abstract class MarketplaceNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected static function brl(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    protected static function url(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }
}
