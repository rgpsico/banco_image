<?php

namespace Tests\Feature\Orders;

use App\Enums\ListingStatus;
use App\Models\License;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_order_totals_and_commission_are_computed_and_frozen_on_the_server(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [], ['personal' => 990, 'commercial' => 2990]);
        $buyer = User::factory()->create();

        // o cliente tenta impor preço/comissão: é ignorado
        Sanctum::actingAs($buyer);
        $res = $this->postJson('/api/v1/orders', ['items' => [['listing' => $listing->ulid, 'license' => 'commercial', 'price_cents' => 1, 'commission_bps' => 0]], 'total_cents' => 1])
            ->assertCreated();

        $res->assertJsonPath('data.status', 'pending')->assertJsonPath('data.total_cents', 2990)->assertJsonPath('data.items.0.price_cents', 2990);
        $res->assertJsonMissingPath('data.items.0.platform_fee_cents')->assertJsonMissingPath('data.items.0.seller_earning_cents'); // comprador não vê a comissão

        $order = Order::first();
        $item = $order->items->first();
        $this->assertSame(2000, $item->commission_bps);
        $this->assertSame(598, $item->platform_fee_cents);      // 20% de 2990
        $this->assertSame(2392, $item->seller_earning_cents);
        $this->assertSame(598, $order->platform_fee_cents);
        $this->assertSame(2990, $order->subtotal_cents);
    }

    public function test_seller_specific_commission_overrides_the_default(): void
    {
        $listing = $this->makeListing($this->makeSeller(['commission_bps' => 1000]), [], ['commercial' => 5000]);

        $this->placeOrder(User::factory()->create(), [[$listing, 'commercial']])->assertCreated();

        $item = Order::first()->items->first();
        $this->assertSame(1000, $item->commission_bps);
        $this->assertSame(500, $item->platform_fee_cents);
        $this->assertSame(4500, $item->seller_earning_cents);
    }

    public function test_default_commission_comes_from_configuration(): void
    {
        config(['mediahub.marketplace.commission_bps' => 3000]);
        $listing = $this->makeListing(null, [], ['commercial' => 1000]);

        $this->placeOrder(User::factory()->create(), [[$listing, 'commercial']])->assertCreated();

        $this->assertSame(300, Order::first()->items->first()->platform_fee_cents);
    }

    public function test_multi_item_order_sums_all_items_from_different_sellers(): void
    {
        $a = $this->makeListing(null, [], ['commercial' => 1000]);
        $b = $this->makeListing(null, [], ['personal' => 555]);

        $this->placeOrder(User::factory()->create(), [[$a, 'commercial'], [$b, 'personal']])->assertCreated()->assertJsonPath('data.total_cents', 1555);

        $order = Order::first();
        $this->assertSame(1555, $order->items->sum('unit_price_cents'));
        $this->assertSame($order->items->sum('platform_fee_cents'), $order->platform_fee_cents);
        $this->assertSame(2, $order->items->pluck('seller_id')->unique()->count());
    }

    public function test_cannot_buy_own_media_unpublished_media_or_unoffered_license_or_repeats(): void
    {
        $seller = $this->makeSeller();
        $own = $this->makeListing($seller);
        $draft = $this->makeListing(null, ['status' => ListingStatus::Draft]);
        $listing = $this->makeListing(null, [], ['commercial' => 1500]);
        $buyer = User::factory()->create();

        $this->placeOrder($seller, [[$own, 'commercial']])->assertStatus(422)->assertJsonValidationErrors('items.0.listing');
        $this->placeOrder($buyer, [[$draft, 'commercial']])->assertStatus(422)->assertJsonValidationErrors('items.0.listing');
        $this->placeOrder($buyer, [[$listing, 'extended']])->assertStatus(422)->assertJsonValidationErrors('items.0.license');
        $this->placeOrder($buyer, [[$listing, 'commercial'], [$listing, 'commercial']])->assertStatus(422);
        $this->assertSame(0, Order::count());

        Sanctum::actingAs($buyer);
        $this->postJson('/api/v1/orders', ['items' => []])->assertStatus(422);
        $this->postJson('/api/v1/orders', ['items' => [['listing' => 'curto', 'license' => 'commercial']]])->assertStatus(422);
    }

    public function test_orders_require_authentication(): void
    {
        $this->postJson('/api/v1/orders', ['items' => []])->assertUnauthorized();
        $this->getJson('/api/v1/orders')->assertUnauthorized();
        $this->getJson('/api/v1/purchases')->assertUnauthorized();
    }

    public function test_idempotency_key_returns_the_same_order(): void
    {
        $listing = $this->makeListing(null, [], ['commercial' => 1500]);
        $buyer = User::factory()->create();

        $first = $this->placeOrder($buyer, [[$listing, 'commercial']], 'chave-1')->assertCreated()->json('data.id');
        $second = $this->placeOrder($buyer, [[$listing, 'commercial']], 'chave-1')->assertOk()->json('data.id'); // mesma chave: mesmo pedido

        $this->assertSame($first, $second);
        $this->assertSame(1, Order::count());

        // outra chave cria outro pedido; a mesma chave de OUTRO comprador não colide
        $this->placeOrder($buyer, [[$listing, 'commercial']], 'chave-2')->assertCreated();
        $this->placeOrder(User::factory()->create(), [[$listing, 'commercial']], 'chave-1')->assertCreated();
        $this->assertSame(3, Order::count());
    }

    public function test_price_is_frozen_when_the_order_is_created(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [], ['commercial' => 1500]);
        $order = $this->placeOrder(User::factory()->create(), [[$listing, 'commercial']])->assertCreated()->json('data.id');

        $listing->prices()->where('license_id', License::where('code', 'commercial')->value('id'))->update(['price_cents' => 9900]);

        $this->getJson("/api/v1/orders/{$order}")->assertOk()->assertJsonPath('data.total_cents', 1500)->assertJsonPath('data.items.0.price_cents', 1500);
    }

    public function test_cannot_rebuy_the_same_license_after_paying_but_another_license_is_fine(): void
    {
        $listing = $this->makeListing(null, [], ['personal' => 500, 'commercial' => 1500]);
        $buyer = User::factory()->create();
        $this->paidOrder($buyer, [[$listing, 'personal']]);

        $this->placeOrder($buyer, [[$listing, 'personal']])->assertStatus(422);
        $this->placeOrder($buyer, [[$listing, 'commercial']])->assertCreated();
    }

    public function test_users_only_see_their_own_orders(): void
    {
        $listing = $this->makeListing();
        $alice = User::factory()->create();
        $id = $this->placeOrder($alice, [[$listing, 'commercial']])->json('data.id');

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$id}/pay")->assertNotFound();
        $this->postJson("/api/v1/orders/{$id}/simulate-payment")->assertNotFound();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_expired_orders_cannot_be_paid_and_the_command_expires_stale_ones(): void
    {
        $listing = $this->makeListing();
        $buyer = User::factory()->create();
        $id = $this->placeOrder($buyer, [[$listing, 'commercial']])->json('data.id');

        $this->travel(2)->hours();
        $this->postJson("/api/v1/orders/{$id}/pay")->assertStatus(422)->assertJsonValidationErrors('order');
        $this->assertSame('expired', Order::first()->status->value);

        $this->travelBack();
        $id2 = $this->placeOrder($buyer, [[$listing, 'commercial']])->json('data.id');
        $this->travel(2)->hours();
        $this->artisan('mediahub:expire-orders')->expectsOutputToContain('Pedidos expirados: 1')->assertSuccessful();
        $this->assertSame('expired', Order::where('ulid', $id2)->first()->status->value);
    }
}
