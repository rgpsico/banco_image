<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Gateways\SimulatedGateway;

class PaymentGatewayManager
{
    public function active(): PaymentGatewayInterface
    {
        return $this->make((string) config('mediahub.payments.gateway'));
    }

    public function make(string $name): PaymentGatewayInterface
    {
        return match ($name) {
            'simulated' => new SimulatedGateway(),
            'asaas' => new \App\Modules\Payments\Gateways\AsaasGateway(),
            default => throw new \InvalidArgumentException("Gateway de pagamento '{$name}' não disponível."),
        };
    }
}
