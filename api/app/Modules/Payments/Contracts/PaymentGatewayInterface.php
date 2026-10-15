<?php

namespace App\Modules\Payments\Contracts;

use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Support\ChargeResult;
use App\Modules\Payments\Support\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Contrato dos gateways de pagamento. Hoje existe o gateway SIMULADO (local/testes);
 * o Asaas entra na Fase 4 implementando esta mesma interface (cobrança, webhook e split).
 */
interface PaymentGatewayInterface
{
    public function name(): string;

    /** Cria a cobrança no provedor e devolve a referência dele. */
    public function createCharge(Order $order, Payment $payment): ChargeResult;

    /**
     * Valida a ASSINATURA do webhook e converte o corpo em um evento normalizado.
     *
     * @throws \App\Modules\Payments\Support\InvalidWebhookException
     */
    public function parseWebhook(Request $request): WebhookEvent;
}
