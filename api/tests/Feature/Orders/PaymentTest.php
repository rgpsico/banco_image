<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\DownloadGrant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Gateways\SimulatedGateway;
use App\Modules\Payments\Services\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    private function pendingPayment(?User $buyer = null, array $prices = ['commercial' => 1500]): array
    {
        $buyer ??= User::factory()->create();
        $listing = $this->makeListing(null, [], $prices);
        $id = $this->placeOrder($buyer, [[$listing, array_key_first($prices)]])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/pay")->assertOk()->assertJsonPath('data.payment.status', 'pending')->assertJsonPath('data.simulated', true);

        return [$buyer, $listing, Order::where('ulid', $id)->first(), Payment::first()];
    }

    public function test_start_payment_is_idempotent_per_order(): void
    {
        [, , $order] = $this->pendingPayment();

        $this->postJson("/api/v1/orders/{$order->ulid}/pay")->assertOk();
        $this->postJson("/api/v1/orders/{$order->ulid}/pay")->assertOk();

        $this->assertSame(1, Payment::count()); // continua um único pagamento pendente
        $this->assertSame(1500, Payment::first()->amount_cents);
    }

    public function test_simulated_confirmation_pays_the_order_creates_download_grants_and_counts_sales(): void
    {
        $buyer = User::factory()->create();
        $a = $this->makeListing(null, [], ['commercial' => 1000]);
        $b = $this->makeListing(null, [], ['personal' => 700]);
        $order = $this->paidOrder($buyer, [[$a, 'commercial'], [$b, 'personal']]);

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(PaymentStatus::Confirmed, Payment::first()->status);
        $this->assertSame(2, DownloadGrant::count());
        $this->assertSame(1, $a->fresh()->sales_count);
        $this->assertSame(1, $b->fresh()->sales_count);
        $this->assertSame(config('mediahub.marketplace.download_max'), DownloadGrant::first()->max_downloads);
    }

    public function test_confirming_twice_does_not_duplicate_anything(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->makeListing();
        $order = $this->paidOrder($buyer, [[$listing, 'commercial']]);

        $this->postJson("/api/v1/orders/{$order->ulid}/simulate-payment")->assertStatus(422); // pedido já pago
        $this->assertSame(1, DownloadGrant::count());
        $this->assertSame(1, $listing->fresh()->sales_count);
        $this->assertSame(1, Payment::count());
    }

    public function test_webhook_requires_a_valid_signature(): void
    {
        [, , , $payment] = $this->pendingPayment();
        $body = ['event_id' => 'evt_1', 'type' => 'payment.confirmed', 'reference' => $payment->gateway_reference];

        $this->webhook($body, 'assinatura-falsa')->assertUnauthorized();
        $this->webhook($body, SimulatedGateway::sign('outro corpo'))->assertUnauthorized();
        $this->call('POST', '/api/v1/webhooks/simulated', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body))->assertUnauthorized(); // sem assinatura

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status); // nenhum efeito
        $this->assertSame(0, DownloadGrant::count());
    }

    public function test_valid_webhook_confirms_and_replays_are_ignored(): void
    {
        [, $listing, $order, $payment] = $this->pendingPayment();
        $body = ['event_id' => 'evt_abc', 'type' => 'payment.confirmed', 'reference' => $payment->gateway_reference];

        $this->webhook($body)->assertOk();
        $this->webhook($body)->assertOk();                                                                    // mesmo evento de novo
        $this->webhook(['event_id' => 'evt_xyz'] + $body)->assertOk();                                        // outro id, mesmo pagamento já confirmado

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(1, DownloadGrant::count());
        $this->assertSame(1, $listing->fresh()->sales_count);
        $this->assertSame('evt_abc', $payment->fresh()->webhook_event_id);
    }

    public function test_webhook_with_malformed_body_or_unknown_reference_or_gateway(): void
    {
        $this->pendingPayment();

        $this->webhook(['type' => 'payment.confirmed'])->assertUnauthorized();                                // corpo incompleto (assinado)
        $this->webhook(['event_id' => 'e', 'type' => 'payment.confirmed', 'reference' => 'sim_inexistente'])->assertOk(); // ignora sem efeito
        $this->webhook(['event_id' => 'e', 'type' => 'payment.confirmed', 'reference' => 'x'], null, 'asaas')->assertNotFound(); // gateway inativo
        $this->assertSame(0, DownloadGrant::count());
    }

    public function test_failed_payment_keeps_the_order_pending_and_allows_a_new_attempt(): void
    {
        [, , $order, $payment] = $this->pendingPayment();

        $this->webhook(['event_id' => 'evt_f', 'type' => 'payment.failed', 'reference' => $payment->gateway_reference])->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->postJson("/api/v1/orders/{$order->ulid}/pay")->assertOk();
        $this->assertSame(2, Payment::count());
    }

    public function test_refund_revokes_download_rights(): void
    {
        $buyer = User::factory()->create();
        $order = $this->paidOrder($buyer, [[$this->makeListing(), 'commercial']]);
        $payment = Payment::first();

        $this->webhook(['event_id' => 'evt_r', 'type' => 'payment.refunded', 'reference' => $payment->gateway_reference])->assertOk();

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertNotNull(DownloadGrant::first()->revoked_at);
    }

    public function test_simulated_gateway_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\LogicException::class);
        new SimulatedGateway();
    }

    public function test_simulated_confirmation_endpoint_does_not_exist_in_production(): void
    {
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$this->makeListing(), 'commercial']])->json('data.id');
        $this->app['env'] = 'production';

        $this->postJson("/api/v1/orders/{$id}/simulate-payment")->assertNotFound();
        $this->assertSame(0, DownloadGrant::count());
    }

    public function test_unknown_gateways_are_not_available(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(PaymentGatewayManager::class)->make('asaas'); // Fase 4
    }

    public function test_money_is_always_stored_as_integer_cents(): void
    {
        [, , $order] = $this->pendingPayment(null, ['commercial' => 1999]);

        $this->assertSame('integer', gettype($order->total_cents));
        $this->assertSame('integer', gettype($order->items->first()->platform_fee_cents));
        $this->assertSame('integer', gettype(Payment::first()->amount_cents));
        $this->assertSame(1999, $order->total_cents);
    }
}
