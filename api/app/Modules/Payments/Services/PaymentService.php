<?php

namespace App\Modules\Payments\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DownloadGrant;
use App\Models\Listing;
use App\Models\Order;
use App\Models\Payment;
use App\Modules\Payments\Support\WebhookEvent;
use App\Notifications\OrderRefunded;
use App\Notifications\PurchaseConfirmed;
use App\Notifications\SaleMade;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private PaymentGatewayManager $gateways)
    {
    }

    /** Inicia (ou retoma) o pagamento do pedido. Um pedido tem no máximo UM pagamento pendente. */
    public function start(Order $order): Payment
    {
        if ($order->status !== OrderStatus::Pending) {
            throw ValidationException::withMessages(['order' => ['Este pedido não está aguardando pagamento.']]);
        }

        if ($order->expires_at && $order->expires_at->isPast()) {
            $order->update(['status' => OrderStatus::Expired]);
            throw ValidationException::withMessages(['order' => ['Este pedido expirou. Faça um novo pedido.']]);
        }

        if ($pending = $order->payments()->where('status', PaymentStatus::Pending->value)->first()) {
            return $pending;
        }

        $gateway = $this->gateways->active();

        return DB::transaction(function () use ($order, $gateway) {
            // Define como cada item será repassado: split automático (vendedor com carteira) ou repasse manual.
            foreach ($order->items()->with('seller.sellerProfile')->get() as $item) {
                $auto = $gateway->supportsSplit() && filled($item->seller->sellerProfile?->asaas_wallet_id);
                $item->update(['payout_status' => $auto ? 'split' : 'pending']);
            }

            $payment = Payment::create([
                'order_id' => $order->id, 'gateway' => $gateway->name(), 'status' => PaymentStatus::Pending,
                'amount_cents' => $order->total_cents, 'currency' => $order->currency,
                'idempotency_key' => 'order:' . $order->id . ':' . Str::random(8),
            ]);

            try {
                $charge = $gateway->createCharge($order->fresh(['items']), $payment);
            } catch (\Throwable $e) {
                report($e);
                Audit::record('payment.charge_failed', ['order' => $order->ulid, 'gateway' => $gateway->name()]);
                throw ValidationException::withMessages(['order' => ['Não foi possível iniciar o pagamento agora. Tente novamente em instantes.']]);
            }

            $payment->update(['gateway_reference' => $charge->reference, 'payload' => array_merge($charge->payload, array_filter(['payment_url' => $charge->paymentUrl]))]);
            Audit::record('payment.started', ['order' => $order->ulid, 'payment' => $payment->ulid, 'gateway' => $gateway->name()]);

            return $payment;
        });
    }

    /**
     * Ponto ÚNICO de entrada de eventos de pagamento (webhook real ou confirmação simulada).
     * Idempotente: repetir o mesmo evento, ou confirmar duas vezes, não gera nada novo.
     */
    public function handle(string $gatewayName, WebhookEvent $event): void
    {
        DB::transaction(function () use ($gatewayName, $event) {
            if (Payment::where('webhook_event_id', $event->eventId)->exists()) {
                return; // evento já processado
            }

            $payment = Payment::where('gateway', $gatewayName)->where('gateway_reference', $event->reference)->lockForUpdate()->first();

            if (! $payment) {
                return; // referência desconhecida: ignora sem erro (o provedor não deve reenviar)
            }

            $order = Order::whereKey($payment->order_id)->lockForUpdate()->first();

            match ($event->type) {
                'payment.confirmed' => $this->confirm($payment, $order, $event),
                'payment.failed' => $this->fail($payment, $event),
                'payment.refunded' => $this->refund($payment, $order, $event),
                default => null,
            };
        });
    }

    private function confirm(Payment $payment, Order $order, WebhookEvent $event): void
    {
        if ($payment->status === PaymentStatus::Confirmed) {
            return;
        }

        $payment->update(['status' => PaymentStatus::Confirmed, 'confirmed_at' => now(), 'webhook_event_id' => $event->eventId]);

        if ($order->status === OrderStatus::Paid) {
            return;
        }

        // Pagamento recebido vale mesmo se o pedido venceu enquanto o cliente pagava.
        $order->update(['status' => OrderStatus::Paid, 'paid_at' => now()]);

        foreach ($order->items as $item) {
            DownloadGrant::firstOrCreate(['order_item_id' => $item->id, 'user_id' => $order->buyer_id], [
                'max_downloads' => (int) config('mediahub.marketplace.download_max'),
                'downloads_count' => 0,
                'expires_at' => now()->addDays((int) config('mediahub.marketplace.download_days')),
            ]);
            Listing::whereKey($item->listing_id)->increment('sales_count');
        }

        // Avisos por e-mail (fila, depois do commit). Falhar ao avisar nunca desfaz um pagamento.
        $order->loadMissing(['buyer', 'items.seller']);
        $order->buyer->notify(PurchaseConfirmed::for($order));
        foreach ($order->items as $item) {
            $item->seller->notify(new SaleMade($item->title_snapshot, $item->seller_earning_cents, explode(' ', trim($order->buyer->name))[0]));
        }

        Audit::record('payment.confirmed', ['order' => $order->ulid, 'payment' => $payment->ulid, 'amount_cents' => $payment->amount_cents]);
    }

    private function fail(Payment $payment, WebhookEvent $event): void
    {
        if ($payment->status !== PaymentStatus::Pending) {
            return;
        }

        $payment->update(['status' => PaymentStatus::Failed, 'failed_at' => now(), 'webhook_event_id' => $event->eventId]);
        Audit::record('payment.failed', ['payment' => $payment->ulid]);
    }

    private function refund(Payment $payment, Order $order, WebhookEvent $event): void
    {
        if ($payment->status !== PaymentStatus::Confirmed) {
            return;
        }

        $payment->update(['status' => PaymentStatus::Refunded, 'webhook_event_id' => $event->eventId]);
        $order->update(['status' => OrderStatus::Refunded]);
        // O download deixa de ser autorizado.
        DownloadGrant::whereIn('order_item_id', $order->items()->pluck('id'))->update(['revoked_at' => now()]);
        Audit::record('payment.refunded', ['order' => $order->ulid]);
        $order->loadMissing('buyer')->buyer->notify(new OrderRefunded($order->ulid, $order->total_cents));
    }

    /** Reembolso pedido pela plataforma: avisa o provedor e aplica o mesmo efeito de um evento "refunded". */
    public function requestRefund(Order $order): void
    {
        $payment = $order->payments()->where('status', PaymentStatus::Confirmed->value)->first();

        if ($order->status !== OrderStatus::Paid || ! $payment) {
            throw ValidationException::withMessages(['order' => ['Só é possível reembolsar pedidos pagos.']]);
        }

        $gateway = $this->gateways->make($payment->gateway);

        try {
            $gateway->refund($payment);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['order' => ['O provedor de pagamento recusou o reembolso. Verifique no painel dele.']]);
        }

        // O webhook de reembolso do provedor chegará depois; é idempotente.
        $this->handle($payment->gateway, new WebhookEvent('refund_' . strtolower((string) Str::ulid()), 'payment.refunded', $payment->gateway_reference));
    }
}
