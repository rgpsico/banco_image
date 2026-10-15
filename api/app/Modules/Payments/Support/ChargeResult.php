<?php

namespace App\Modules\Payments\Support;

final class ChargeResult
{
    public function __construct(public readonly string $reference, public readonly ?string $paymentUrl = null, public readonly array $payload = [])
    {
    }
}
