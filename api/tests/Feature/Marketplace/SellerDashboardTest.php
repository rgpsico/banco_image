<?php

namespace Tests\Feature\Marketplace;

use App\Models\Order;
use App\Models\User;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class SellerDashboardTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_stats_sales_and_commission_report_add_up(): void
    {
        $seller = $this->makeSeller();
        $a = $this->makeListing($seller, ['title' => 'Foto A'], ['commercial' => 1000, 'personal' => 500]);
        $b = $this->makeListing($seller, ['title' => 'Foto B'], ['commercial' => 2500]);
        $other = $this->makeListing(null, ['title' => 'De outro vendedor'], ['commercial' => 9999]);

        $this->paidOrder(User::factory()->create(['name' => 'Carla Souza']), [[$a, 'commercial'], [$b, 'commercial']]); // 1000 + 2500
        $this->paidOrder(User::factory()->create(['name' => 'Davi Lima']), [[$a, 'personal'], [$other, 'commercial']]);   // 500 (+ venda alheia)
        $this->placeOrder(User::factory()->create(), [[$b, 'commercial']]);                                              // pendente: não conta

        Sanctum::actingAs($seller);
        $stats = $this->getJson('/api/v1/seller/stats')->assertOk()->json('data');

        $this->assertSame(3, $stats['sales_count']);
        $this->assertSame(4000, $stats['gross_cents']);                  // 1000 + 2500 + 500
        $this->assertSame(800, $stats['platform_fee_cents']);            // 20%
        $this->assertSame(3200, $stats['earnings_cents']);
        $this->assertSame($stats['gross_cents'], $stats['platform_fee_cents'] + $stats['earnings_cents']);
        $this->assertCount(30, $stats['last_30_days']);
        $this->assertSame(3, collect($stats['last_30_days'])->sum('sales'));
        $this->assertSame(['published' => 2], $stats['listings']);

        $sales = $this->getJson('/api/v1/seller/sales')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertEqualsCanonicalizing(['Carla', 'Carla', 'Davi'], array_column($sales, 'buyer')); // só o primeiro nome
        $this->assertNotContains('De outro vendedor', array_column($sales, 'title'));
        $this->assertSame(['commission_bps', 'earnings_cents', 'platform_fee_cents'], array_values(array_intersect(['commission_bps', 'earnings_cents', 'platform_fee_cents'], array_keys($sales[0]))));
        $this->assertArrayNotHasKey('email', $sales[0]);

        $report = $this->getJson('/api/v1/seller/reports/commissions')->assertOk()->json('data');
        $this->assertCount(1, $report['rows']);
        $this->assertSame(now()->format('Y-m'), $report['rows'][0]['month']);
        $this->assertSame(['sales' => 3, 'gross_cents' => 4000, 'platform_fee_cents' => 800, 'earnings_cents' => 3200], $report['totals']);
    }

    public function test_refunded_orders_do_not_count_and_other_sellers_data_is_invisible(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [], ['commercial' => 1000]);
        $this->paidOrder(User::factory()->create(), [[$listing, 'commercial']]);
        $this->webhook(['event_id' => 'evt_r', 'type' => 'payment.refunded', 'reference' => Payment::first()->gateway_reference])->assertOk();

        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/seller/stats')->assertJsonPath('data.sales_count', 0)->assertJsonPath('data.earnings_cents', 0);
        $this->getJson('/api/v1/seller/sales')->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->makeSeller());
        $this->getJson('/api/v1/seller/sales')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/seller/reports/commissions')->assertJsonPath('data.totals.sales', 0);
    }

    public function test_commission_report_filters_by_period_and_validates_dates(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller, [], ['commercial' => 1000, 'personal' => 600]);
        $this->travelTo(now()->subMonths(2));
        $this->paidOrder(User::factory()->create(), [[$listing, 'commercial']]);
        $this->travelBack();
        $this->paidOrder(User::factory()->create(), [[$listing, 'personal']]);

        Sanctum::actingAs($seller);
        $all = $this->getJson('/api/v1/seller/reports/commissions')->json('data');
        $this->assertCount(2, $all['rows']);
        $this->assertSame(1600, $all['totals']['gross_cents']);

        $recent = $this->getJson('/api/v1/seller/reports/commissions?from=' . now()->startOfMonth()->toDateString())->json('data');
        $this->assertSame(600, $recent['totals']['gross_cents']);
        $this->getJson('/api/v1/seller/reports/commissions?from=nao-e-data')->assertStatus(422);
    }

    public function test_non_sellers_cannot_see_the_dashboard(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/seller/stats')->assertForbidden();
        $this->getJson('/api/v1/seller/sales')->assertForbidden();
        $this->getJson('/api/v1/seller/reports/commissions')->assertForbidden();
    }
}
