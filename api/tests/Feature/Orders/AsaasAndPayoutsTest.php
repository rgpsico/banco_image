<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\DownloadGrant;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Modules\Payments\Gateways\AsaasGateway;
use App\Notifications\OrderRefunded;
use App\Notifications\PurchaseConfirmed;
use App\Notifications\SaleMade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class AsaasAndPayoutsTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    private function useAsaas(): void
    {
        config(['mediahub.payments.gateway' => 'asaas', 'mediahub.payments.asaas.key' => 'chave-de-teste',
            'mediahub.payments.asaas.url' => 'https://asaas.test/v3', 'mediahub.payments.asaas.webhook_token' => 'token-secreto']);
        Http::fake([
            'asaas.test/v3/customers' => Http::response(['id' => 'cus_1']),
            'asaas.test/v3/payments/pay_1/refund' => Http::response(['status' => 'REFUNDED']),
            'asaas.test/v3/payments' => Http::response(['id' => 'pay_1', 'status' => 'PENDING', 'invoiceUrl' => 'https://asaas.test/i/pay_1']),
        ]);
    }

    private function asaasWebhook(array $body, string $token = 'token-secreto')
    {
        return $this->call('POST', '/api/v1/webhooks/asaas', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_ASAAS_ACCESS_TOKEN' => $token,
        ], json_encode($body));
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->forceFill(['is_platform_admin' => true])->save();

        return $a;
    }

    public function test_asaas_refuses_to_start_without_configuration(): void
    {
        config(['mediahub.payments.asaas.key' => null]);
        $this->expectException(\LogicException::class);
        new AsaasGateway();
    }

    public function test_charge_sends_the_split_for_sellers_with_a_wallet_and_returns_the_payment_url(): void
    {
        $this->useAsaas();
        $seller = $this->makeSeller(['asaas_wallet_id' => 'wallet-123', 'commission_bps' => 2000]);
        $listing = $this->makeListing($seller, [], ['commercial' => 1000]);
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$listing, 'commercial']])->json('data.id');

        $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertOk()->assertJsonPath('data.payment_url', 'https://asaas.test/i/pay_1');

        Http::assertSent(function ($r) {
            if (! str_ends_with($r->url(), '/v3/payments')) {
                return false;
            }
            $b = $r->data();

            return $r->hasHeader('access_token', 'chave-de-teste') && $b['value'] == 10.0 && $b['externalReference'] === $b['externalReference']
                && $b['split'] === [['walletId' => 'wallet-123', 'fixedValue' => 8.0]]; // 80% para o vendedor, 20% ficam na plataforma
        });
        $this->assertSame('split', OrderItem::first()->payout_status);
        $this->assertSame('cus_1', $buyer->fresh()->asaas_customer_id);
    }

    public function test_asaas_requires_a_valid_cpf_or_cnpj_stores_it_encrypted_and_sends_it_to_the_provider(): void
    {
        $this->useAsaas();
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$this->makeListing(), 'commercial']])->json('data.id');

        $this->postJson("/api/v1/orders/{$id}/pay")->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj');
        $this->postJson("/api/v1/orders/{$id}/pay", ['cpf_cnpj' => '111.111.111-11'])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj'); // dígitos iguais
        $this->postJson("/api/v1/orders/{$id}/pay", ['cpf_cnpj' => '529.982.247-24'])->assertStatus(422)->assertJsonValidationErrors('cpf_cnpj'); // dígito verificador errado
        Http::assertNothingSent();

        $this->postJson("/api/v1/orders/{$id}/pay", ['cpf_cnpj' => '529.982.247-25'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/customers') && $r->data()['cpfCnpj'] === '52998224725');
        $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $buyer->id)->value('cpf_cnpj');
        $this->assertStringNotContainsString('52998224725', $raw); // no banco fica criptografado
        $this->assertSame('52998224725', $buyer->fresh()->cpf_cnpj);
        $this->assertStringNotContainsString('52998224725', $this->getJson('/api/v1/auth/me')->getContent()); // nunca aparece nas respostas

        // próximos pagamentos não pedem de novo
        $this->postJson("/api/v1/orders/{$id}/pay")->assertOk();
    }

    public function test_cpf_and_cnpj_validation_rule(): void
    {
        foreach (['529.982.247-25', '52998224725', '11.222.333/0001-81'] as $ok) {
            $this->assertTrue(\App\Rules\CpfCnpj::isValid($ok), $ok);
        }
        foreach (['', '123', '000.000.000-00', '529.982.247-24', '11.222.333/0001-80', 'abc'] as $bad) {
            $this->assertFalse(\App\Rules\CpfCnpj::isValid($bad), $bad);
        }
    }

    public function test_the_simulated_gateway_does_not_ask_for_a_tax_id(): void
    {
        $id = $this->placeOrder(User::factory()->create(), [[$this->makeListing(), 'commercial']])->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/pay")->assertOk()->assertJsonPath('data.simulated', true);
    }

    public function test_sellers_without_a_wallet_get_no_split_and_are_paid_manually(): void
    {
        $this->useAsaas();
        $listing = $this->makeListing(null, [], ['commercial' => 1000]);
        $id = $this->placeOrder(User::factory()->create(), [[$listing, 'commercial']])->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v3/payments') && ! array_key_exists('split', $r->data()));
        $this->assertSame('pending', OrderItem::first()->payout_status);
    }

    public function test_provider_failure_returns_a_clean_error_and_leaves_no_pending_payment(): void
    {
        Http::fake(['asaas.test/v3/customers' => Http::response(['id' => 'cus_1']), 'asaas.test/v3/payments' => Http::response(['errors' => [['description' => 'segredo interno']]], 500)]);
        $this->useAsaas();
        $id = $this->placeOrder(User::factory()->create(), [[$this->makeListing(), 'commercial']])->json('data.id');

        $res = $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertStatus(422)->assertJsonValidationErrors('order');
        $this->assertStringNotContainsString('segredo', $res->getContent());
        $this->assertSame(0, Payment::count());
    }

    public function test_asaas_webhook_needs_the_token_confirms_once_and_ignores_replays(): void
    {
        Notification::fake();
        $this->useAsaas();
        $listing = $this->makeListing();
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$listing, 'commercial']])->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertOk();
        $body = ['id' => 'evt_1', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_1']];

        $this->asaasWebhook($body, 'errado')->assertUnauthorized();
        $this->assertSame(OrderStatus::Pending->value, \App\Models\Order::first()->status->value);

        $this->asaasWebhook($body)->assertOk();
        $this->asaasWebhook($body)->assertOk(); // reenvio
        $this->asaasWebhook(['id' => 'evt_2', 'event' => 'PAYMENT_RECEIVED', 'payment' => ['id' => 'pay_1']])->assertOk(); // Pix/cartão: segundo evento

        $this->assertSame(OrderStatus::Paid, \App\Models\Order::first()->status);
        $this->assertSame(1, DownloadGrant::count());
        Notification::assertSentToTimes($buyer, PurchaseConfirmed::class, 1);
        Notification::assertSentToTimes($listing->seller, SaleMade::class, 1);
    }

    public function test_admin_refund_goes_through_the_provider_and_revokes_downloads(): void
    {
        Notification::fake();
        $this->useAsaas();
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$this->makeListing(), 'commercial']])->json('data.id');
        $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertOk();
        $this->asaasWebhook(['id' => 'evt_1', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_1']])->assertOk();

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/admin/orders/{$id}/refund")->assertNotFound(); // a área de admin nem aparece para quem não é admin

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/orders/{$id}/refund")->assertOk()->assertJsonPath('data.status', 'refunded');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/payments/pay_1/refund'));
        $this->assertNotNull(DownloadGrant::first()->revoked_at);
        Notification::assertSentTo($buyer, OrderRefunded::class);

        $this->postJson("/api/v1/admin/orders/{$id}/refund")->assertStatus(422); // já reembolsado
    }

    public function test_refund_is_rejected_for_unpaid_orders_and_when_the_provider_refuses(): void
    {
        Http::fake(['asaas.test/v3/payments/pay_1/refund' => Http::response(['errors' => []], 400)]); // o primeiro stub que casa vence
        $this->useAsaas();
        $id = $this->placeOrder(User::factory()->create(), [[$this->makeListing(), 'commercial']])->json('data.id');
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/orders/{$id}/refund")->assertStatus(422); // pendente

        Sanctum::actingAs(\App\Models\Order::first()->buyer);
        $this->postJson("/api/v1/orders/{$id}/pay", ["cpf_cnpj" => "529.982.247-25"])->assertOk();
        $this->asaasWebhook(['id' => 'evt_1', 'event' => 'PAYMENT_CONFIRMED', 'payment' => ['id' => 'pay_1']])->assertOk();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/orders/{$id}/refund")->assertStatus(422);
        $this->assertSame(OrderStatus::Paid, \App\Models\Order::first()->status); // nada mudou
    }

    public function test_manual_payouts_are_listed_per_seller_and_can_be_marked_as_paid(): void
    {
        $seller = $this->makeSeller(['commission_bps' => 2000]);
        $this->paidOrder(User::factory()->create(), [[$this->makeListing($seller, [], ['commercial' => 1000]), 'commercial']]);
        $this->paidOrder(User::factory()->create(), [[$this->makeListing($seller, [], ['commercial' => 500]), 'commercial']]);

        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/admin/payouts')->assertNotFound();

        Sanctum::actingAs($this->admin());
        $this->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owed_cents', 1200)->assertJsonPath('data.0.sales', 2);

        $this->postJson("/api/v1/admin/payouts/{$seller->sellerProfile->slug}/mark-paid", ['note' => 'Pix 15/10'])->assertOk()->assertJsonPath('data.items', 2);
        $this->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('paid_manual', OrderItem::first()->payout_status);
    }

    public function test_refunded_orders_are_not_owed_to_the_seller(): void
    {
        $seller = $this->makeSeller();
        $order = $this->paidOrder(User::factory()->create(), [[$this->makeListing($seller), 'commercial']]);
        Sanctum::actingAs($this->admin());
        $this->postJson("/api/v1/admin/orders/{$order->ulid}/refund")->assertOk();

        $this->getJson('/api/v1/admin/payouts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_admin_orders_list_and_wallet_validation(): void
    {
        $seller = $this->makeSeller();
        $this->paidOrder(User::factory()->create(['email' => 'comprador@example.test']), [[$this->makeListing($seller), 'commercial']]);
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/orders?status=paid')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.buyer.email', 'comprador@example.test');
        $this->getJson('/api/v1/admin/orders?status=bogus')->assertStatus(422);

        $slug = $seller->sellerProfile->slug;
        $this->patchJson("/api/v1/admin/sellers/{$slug}", ['asaas_wallet_id' => 'x; drop table'])->assertStatus(422);
        $this->patchJson("/api/v1/admin/sellers/{$slug}", ['asaas_wallet_id' => 'abc-123'])->assertOk()->assertJsonPath('data.has_wallet', true);

        // a carteira nunca vaza em respostas públicas ou do próprio vendedor
        $this->assertStringNotContainsString('abc-123', $this->getJson("/api/v1/market/sellers/{$slug}")->getContent());
        Sanctum::actingAs($seller);
        $this->assertStringNotContainsString('abc-123', $this->getJson('/api/v1/seller/profile')->getContent());
    }

    public function test_payment_emails_are_queued_after_confirmation(): void
    {
        Notification::fake();
        $buyer = User::factory()->create();
        $listing = $this->makeListing();
        $this->paidOrder($buyer, [[$listing, 'commercial']]);

        Notification::assertSentTo($buyer, PurchaseConfirmed::class, fn ($n) => in_array('mail', $n->via($buyer)) && $n instanceof \Illuminate\Contracts\Queue\ShouldQueue);
        Notification::assertSentTo($listing->seller, SaleMade::class, function ($n) use ($listing) {
            $mail = $n->toMail($listing->seller);

            return $n->buyerFirstName !== '' && ! str_contains(implode(' ', $mail->introLines), 'example.test'); // sem e-mail do comprador
        });
    }

    public function test_r2_disk_is_s3_compatible_private_and_swappable_without_touching_the_app(): void
    {
        config(['filesystems.disks.r2.key' => 'k', 'filesystems.disks.r2.secret' => 's', 'filesystems.disks.r2.bucket' => 'b',
            'filesystems.disks.r2.endpoint' => 'https://acct.r2.cloudflarestorage.com', 'mediahub.disk' => 'r2']);

        $this->assertSame('s3', config('filesystems.disks.r2.driver'));
        $this->assertSame('private', config('filesystems.disks.r2.visibility'));
        $this->assertTrue(config('filesystems.disks.r2.use_path_style_endpoint'));
        $this->assertInstanceOf(\Illuminate\Filesystem\AwsS3V3Adapter::class, Storage::disk('r2')); // driver instalado; sem rede nem bucket real
        $this->assertSame('r2', config('mediahub.disk'));
    }
}
