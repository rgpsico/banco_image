<?php

namespace App\Modules\Payments\Support;

/** Evento normalizado de pagamento. type: payment.confirmed | payment.failed | payment.refunded */
final class WebhookEvent
{
    public function __construct(public readonly string $eventId, public readonly string $type, public readonly string $reference)
    {
    }
}
