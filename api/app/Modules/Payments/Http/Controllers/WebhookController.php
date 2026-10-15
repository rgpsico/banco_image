<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\PaymentGatewayManager;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Support\InvalidWebhookException;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Webhooks de pagamento: assinatura validada ANTES de qualquer efeito; processamento idempotente. */
class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway, PaymentGatewayManager $gateways, PaymentService $payments): JsonResponse
    {
        // Só o gateway ativo recebe webhooks.
        abort_unless($gateway === config('mediahub.payments.gateway'), 404);

        $driver = $gateways->make($gateway);

        try {
            $event = $driver->parseWebhook($request);
        } catch (InvalidWebhookException $e) {
            Audit::record('webhook.rejected', ['gateway' => $gateway, 'reason' => $e->getMessage()]);

            return response()->json(['message' => 'Webhook inválido.'], 401);
        }

        $payments->handle($gateway, $event);

        return response()->json(['ok' => true]);
    }
}
