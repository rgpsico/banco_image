<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Models\ListingReport;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class ReportsAndAdminTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    private function report($listing, $by, string $reason = 'copyright')
    {
        Sanctum::actingAs($by);

        return $this->postJson("/api/v1/market/listings/{$listing->ulid}/reports", ['reason' => $reason, 'details' => '<b>Foto copiada</b> do meu site']);
    }

    public function test_only_logged_users_can_report_once_and_not_their_own_listing(): void
    {
        $listing = $this->makeListing();

        $this->app['auth']->forgetGuards(); // makeListing() autenticou como vendedor; aqui é um visitante
        $this->postJson("/api/v1/market/listings/{$listing->ulid}/reports", ['reason' => 'copyright'])->assertUnauthorized();

        $user = User::factory()->create();
        $this->report($listing, $user)->assertCreated();
        $this->report($listing, $user)->assertStatus(422)->assertJsonValidationErrors('listing');
        $this->report($listing, $listing->seller)->assertStatus(422);
        $this->assertSame('Foto copiada do meu site', ListingReport::first()->details); // sem HTML
        $this->assertSame(1, ListingReport::count());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/market/listings/{$listing->ulid}/reports", ['reason' => 'invalido'])->assertStatus(422);
    }

    public function test_enough_open_reports_auto_suspend_the_listing_until_moderation(): void
    {
        $listing = $this->makeListing();

        $this->report($listing, User::factory()->create())->assertCreated();
        $this->report($listing, User::factory()->create(), 'misleading')->assertCreated();
        $this->assertSame(ListingStatus::Published, $listing->fresh()->status);

        $this->report($listing, User::factory()->create(), 'inappropriate')->assertCreated(); // 3ª denúncia aberta
        $this->assertSame(ListingStatus::Suspended, $listing->fresh()->status);
        $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertNotFound();      // sumiu do catálogo
        $this->getJson('/api/v1/market/listings')->assertJsonCount(0, 'data');
    }

    public function test_reports_cannot_target_unpublished_listings(): void
    {
        $draft = $this->makeListing(null, ['status' => ListingStatus::Draft]);

        $this->report($draft, User::factory()->create())->assertNotFound();
    }

    public function test_admin_resolves_reports_by_dismissing_or_suspending(): void
    {
        $listing = $this->makeListing();
        $this->report($listing, User::factory()->create())->assertCreated();
        $this->report($listing, User::factory()->create(), 'inappropriate')->assertCreated();
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/v1/admin/reports')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertArrayNotHasKey('email', $list[0]);

        $this->postJson("/api/v1/admin/reports/{$list[0]['id']}/resolve", ['action' => 'dismiss', 'note' => 'Sem fundamento'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->assertSame(ListingStatus::Published, $listing->fresh()->status);

        $this->postJson("/api/v1/admin/reports/{$list[1]['id']}/resolve", ['action' => 'suspend_listing', 'note' => 'Violação confirmada'])->assertOk()->assertJsonPath('data.status', 'actioned');
        $this->assertSame(ListingStatus::Suspended, $listing->fresh()->status);
        $this->postJson("/api/v1/admin/reports/{$list[1]['id']}/resolve", ['action' => 'dismiss'])->assertNotFound(); // já resolvida
        $this->postJson("/api/v1/admin/reports/{$list[0]['id']}/resolve", ['action' => 'apagar-tudo'])->assertStatus(422); // ação inexistente
    }

    public function test_admin_area_is_hidden_from_regular_users(): void
    {
        Sanctum::actingAs($this->makeSeller());

        $this->getJson('/api/v1/admin/reports')->assertNotFound();
        $this->getJson('/api/v1/admin/sellers')->assertNotFound();
        $this->patchJson('/api/v1/admin/sellers/x', ['status' => 'suspended'])->assertNotFound();
    }

    public function test_admin_can_suspend_a_seller_which_hides_their_listings_and_set_their_commission(): void
    {
        $seller = $this->makeSeller(['slug' => 'ana-foto']);
        $listing = $this->makeListing($seller, [], ['commercial' => 1000]);
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $this->patchJson('/api/v1/admin/sellers/ana-foto', ['commission_bps' => 1500])->assertOk()->assertJsonPath('data.effective_commission_bps', 1500);
        $this->patchJson('/api/v1/admin/sellers/ana-foto', ['commission_bps' => 6000])->assertStatus(422);   // teto de 50%
        $this->patchJson('/api/v1/admin/sellers/ana-foto', ['commission_bps' => null])->assertOk()->assertJsonPath('data.effective_commission_bps', 2000); // volta ao padrão

        $this->patchJson('/api/v1/admin/sellers/ana-foto', ['status' => 'suspended'])->assertOk();
        $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertNotFound();
        $this->getJson('/api/v1/market/sellers/ana-foto')->assertNotFound();

        // vendedor suspenso não compra... vende nada e não acessa a área
        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/seller/listings')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/v1/admin/sellers/ana-foto', ['status' => 'active'])->assertOk();
        $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertOk();
        $this->getJson('/api/v1/admin/sellers')->assertOk()->assertJsonPath('data.0.slug', 'ana-foto');
        $this->assertSame(2, SellerProfile::count() + 1); // admin não virou vendedor sozinho
    }
}
