<?php

namespace App\Modules\Payments\Gateways;

use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Contracts\PaymentGatewayInterface;
use App\Modules\Payments\Support\ChargeResult;
use App\Modules\Payments\Support\InvalidWebhookException;
use App\Modules\Payments\Support\WebhookEvent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Pagamentos reais pelo Asaas (Pix, boleto e cartão numa página de pagamento do Asaas) com split para os vendedores.
 * Configuração: ASAAS_KEY, ASAAS_URL e ASAAS_WEBHOOK_TOKEN. Sem chave ou sem token, o gateway recusa-se a iniciar.
 */
class AsaasGateway implements PaymentGatewayInterface
{
    private const EVENT_MAP = [
        'PAYMENT_CONFIRMED' => 'payment.confirmed',
        'PAYMENT_RECEIVED' => 'payment.confirmed',
        'PAYMENT_REFUNDED' => 'payment.refunded',
        'PAYMENT_DELETED' => 'payment.failed',
        'PAYMENT_OVERDUE' => 'payment.failed',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED' => 'payment.failed',
    ];

    public function __construct()
    {
        if (blank(config('mediahub.payments.asaas.key')) || blank(config('mediahub.payments.asaas.webhook_token'))) {
            throw new \LogicException('Asaas não configurado: defina ASAAS_KEY e ASAAS_WEBHOOK_TOKEN.');
        }
    }

    public function name(): string
    {
        return 'asaas';
    }

    public function supportsSplit(): bool
    {
        return true;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('mediahub.payments.asaas.url'), '/'))
            ->withHeaders(['access_token' => (string) config('mediahub.payments.asaas.key'), 'User-Agent' => 'mediahub'])
            ->acceptJson()->timeout(20);
    }

    public function createCharge(Order $order, Payment $payment): ChargeResult
    {
        $order->loadMissing(['buyer', 'items.seller.sellerProfile']);
        $customerId = $this->customerId($order);

        $body = [
            'customer' => $customerId,
            'billingType' => 'UNDEFINED', // o comprador escolhe Pix, boleto ou cartão na página do Asaas
            'value' => $this->reais($order->total_cents),
            'dueDate' => now()->addDays(3)->toDateString(),
            'description' => 'MediaHub - pedido ' . $order->ulid,
            'externalReference' => $order->ulid,
        ];

        // Split: cada vendedor com carteira recebe a sua parte; o restante fica com a plataforma (comissão + quem ainda não tem carteira).
        $split = $order->items->filter(fn ($i) => $i->payout_status === 'split')
            ->groupBy(fn ($i) => $i->seller->sellerProfile->asaas_wallet_id)
            ->map(fn ($items, $wallet) => ['walletId' => $wallet, 'fixedValue' => $this->reais($items->sum('seller_earning_cents'))])
            ->values()->all();
        if ($split) {
            $body['split'] = $split;
        }

        $res = $this->http()->post('/payments', $body);

        if (! $res->successful() || ! $res->json('id')) {
            throw new \RuntimeException('Asaas recusou a cobrança (HTTP ' . $res->status() . ').');
        }

        return new ChargeResult((string) $res->json('id'), $res->json('invoiceUrl'), ['asaas_status' => $res->json('status')]);
    }

    /** Reaproveita o cliente do Asaas do comprador; cria uma vez e guarda. */
    private function customerId(Order $order): string
    {
        $buyer = $order->buyer;

        if ($buyer->asaas_customer_id) {
            return $buyer->asaas_customer_id;
        }

        $res = $this->http()->post('/customers', ['name' => $buyer->name, 'email' => $buyer->email, 'externalReference' => $buyer->ulid]);

        if (! $res->successful() || ! $res->json('id')) {
            throw new \RuntimeException('Asaas recusou o cadastro do cliente (HTTP ' . $res->status() . ').');
        }

        $buyer->forceFill(['asaas_customer_id' => $res->json('id')])->save();

        return $res->json('id');
    }

    public function refund(Payment $payment): void
    {
        $res = $this->http()->post("/payments/{$payment->gateway_reference}/refund", ['description' => 'Reembolso solicitado pela plataforma']);

        if (! $res->successful()) {
            throw new \RuntimeException('Asaas recusou o reembolso (HTTP ' . $res->status() . ').');
        }
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $expected = (string) config('mediahub.payments.asaas.webhook_token');
        $given = (string) $request->header('asaas-access-token');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            throw new InvalidWebhookException('Token inválido.');
        }

        $body = json_decode($request->getContent(), true);
        $event = is_array($body) ? ($body['event'] ?? null) : null;
        $paymentId = is_array($body) ? ($body['payment']['id'] ?? null) : null;

        if (! $event || ! $paymentId) {
            throw new InvalidWebhookException('Corpo inválido.');
        }

        // id do evento (quando o Asaas envia) ou uma impressão estável: o mesmo evento repetido não é processado de novo.
        $eventId = (string) ($body['id'] ?? hash('sha256', $event . '|' . $paymentId));

        return new WebhookEvent($eventId, self::EVENT_MAP[$event] ?? 'ignored', (string) $paymentId);
    }

    private function reais(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
