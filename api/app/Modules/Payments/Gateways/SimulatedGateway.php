<?php

namespace App\Modules\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Support\ChargeResult;
use App\Modules\Payments\Support\InvalidWebhookException;
use App\Modules\Payments\Support\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gateway SIMULADO: nenhum dinheiro real. Existe SOMENTE para ambiente local e testes
 * (recusa-se a funcionar em produção). Os webhooks seguem o mesmo contrato dos reais: assinatura HMAC + id de evento.
 */
class SimulatedGateway implements PaymentGatewayInterface
{
    public function __construct()
    {
        if (app()->isProduction() || ! app()->environment(config('mediahub.payments.simulated_environments'))) {
            throw new \LogicException('O gateway simulado só pode ser usado em ambientes de teste (nunca em produção).');
        }
    }

    public function name(): string
    {
        return 'simulated';
    }

    public function requiresBuyerTaxId(): bool
    {
        return false;
    }

    public function supportsSplit(): bool
    {
        return false; // repasse manual no ambiente de testes
    }

    public function refund(Payment $payment): void
    {
        // nada a devolver: é simulação
    }

    public function createCharge(Order $order, Payment $payment): ChargeResult
    {
        return new ChargeResult('sim_' . strtolower((string) Str::ulid()), null, ['simulated' => true]);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $given = (string) $request->header('X-Signature');
        $expected = self::sign($request->getContent());

        if ($given === '' || ! hash_equals($expected, $given)) {
            throw new InvalidWebhookException('Assinatura inválida.');
        }

        $body = json_decode($request->getContent(), true);

        if (! is_array($body) || empty($body['event_id']) || empty($body['type']) || empty($body['reference'])) {
            throw new InvalidWebhookException('Corpo inválido.');
        }

        return new WebhookEvent((string) $body['event_id'], (string) $body['type'], (string) $body['reference']);
    }

    public static function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) config('mediahub.payments.simulated_secret'));
    }
}
