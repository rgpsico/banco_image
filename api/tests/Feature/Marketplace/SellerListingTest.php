<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use App\Enums\TenantRole;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class SellerListingTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_user_can_become_seller_once_after_accepting_terms(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/seller/join', ['display_name' => 'Ana Foto', 'bio' => '<b>Fotógrafa</b> carioca'])
            ->assertStatus(422)->assertJsonValidationErrors('accept_terms');

        $this->postJson('/api/v1/seller/join', ['display_name' => 'Ana Foto', 'bio' => '<b>Fotógrafa</b> carioca', 'accept_terms' => true])
            ->assertCreated()->assertJsonPath('data.slug', 'ana-foto')->assertJsonPath('data.bio', 'Fotógrafa carioca')
            ->assertJsonMissingPath('data.id')->assertJsonMissingPath('data.user_id');

        $this->postJson('/api/v1/seller/join', ['display_name' => 'Outra', 'accept_terms' => true])->assertStatus(422);

        // outro usuário com o mesmo nome recebe slug diferente
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/seller/join', ['display_name' => 'Ana Foto', 'accept_terms' => true])->assertCreated()->assertJsonPath('data.slug', 'ana-foto-2');
    }

    public function test_only_active_sellers_reach_the_seller_area(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/seller/listings')->assertForbidden();
        $this->getJson('/api/v1/seller/status')->assertOk()->assertJsonPath('data', null);

        $suspended = $this->makeSeller(['status' => 'suspended']);
        Sanctum::actingAs($suspended);
        $this->getJson('/api/v1/seller/listings')->assertForbidden();
    }

    public function test_seller_creates_a_draft_from_own_media_with_normalized_tags_and_prices(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);

        $res = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid))->assertCreated();

        $res->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tags', ['surf', 'onda']) // minúsculas, sem espaços e sem repetidas
            ->assertJsonCount(2, 'data.prices')
            ->assertJsonPath('data.prices.0.price_cents', 990);
        $this->assertNotNull($res->json('data.rights_declared_at'));
        $this->assertNotNull($res->json('data.media.preview_url')); // dono vê a prévia real
    }

    public function test_cannot_sell_media_of_another_project_or_as_viewer_or_twice(): void
    {
        $seller = $this->makeSeller();
        $other = $this->makeSeller();
        $foreign = $this->realMediaFor($other);
        $mine = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);

        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($foreign->ulid))->assertStatus(422)->assertJsonValidationErrors('media_id');

        // viewer no projeto da mídia não pode publicá-la
        $viewer = $this->makeSeller();
        $mine->tenant->users()->attach($viewer->id, ['role' => TenantRole::Viewer->value]);
        Sanctum::actingAs($viewer);
        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($mine->ulid))->assertStatus(422);

        Sanctum::actingAs($seller);
        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($mine->ulid))->assertCreated();
        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($mine->ulid))->assertStatus(422)->assertJsonValidationErrors('media_id');
    }

    public function test_price_rules(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);

        foreach ([100, 10_000_001] as $cents) { // abaixo do mínimo / acima do máximo
            $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid, ['prices' => [['license' => 'personal', 'price_cents' => $cents]]]))
                ->assertStatus(422);
        }
        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid, ['prices' => [['license' => 'inexistente', 'price_cents' => 1000]]]))->assertStatus(422);
        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid, ['prices' => [['license' => 'personal', 'price_cents' => 12.5]]]))->assertStatus(422);
        $this->assertSame(0, Listing::count());
    }

    public function test_submit_requires_rights_declaration_release_for_people_and_ready_media(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid, ['rights_declared' => false, 'has_people' => true, 'prices' => []]))
            ->assertCreated()->json('data.id');

        $res = $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertStatus(422);
        $res->assertJsonValidationErrors(['prices', 'rights_declared', 'model_release_confirmed']);

        $this->patchJson("/api/v1/seller/listings/{$id}", [
            'rights_declared' => true, 'model_release_confirmed' => true, 'prices' => [['license' => 'personal', 'price_cents' => 500]],
        ])->assertOk();
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');
    }

    public function test_submit_sends_to_moderation_and_admin_approves_with_watermark(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid))->json('data.id');
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review');

        // quem não é admin não enxerga a área de moderação (404) nem aprova a própria publicação
        $this->getJson('/api/v1/admin/listings')->assertNotFound();
        $this->postJson("/api/v1/admin/listings/{$id}/approve")->assertNotFound();

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/listings')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.seller.slug', $seller->sellerProfile->slug);
        $this->postJson("/api/v1/admin/listings/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'published');

        $kinds = $media->fresh()->variants->pluck('kind.value')->all();
        $this->assertContains('watermarked', $kinds);
        $this->assertContains('wm_thumb', $kinds);
        $this->assertNotNull(Listing::first()->published_at);
    }

    public function test_rejection_requires_a_reason_and_seller_can_fix_and_resubmit(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid))->json('data.id');
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/listings/{$id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/admin/listings/{$id}/reject", ['reason' => 'Título enganoso.'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/v1/admin/listings/{$id}/reject", ['reason' => 'de novo'])->assertStatus(422); // transição inválida

        Sanctum::actingAs($seller);
        $this->getJson("/api/v1/seller/listings/{$id}")->assertJsonPath('data.rejection_reason', 'Título enganoso.');
        $this->patchJson("/api/v1/seller/listings/{$id}", ['title' => 'Surfista em Arpoador ao amanhecer'])->assertOk();
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'pending_review')->assertJsonPath('data.rejection_reason', null);
    }

    public function test_published_listing_only_allows_price_changes_and_can_be_unpublished(): void
    {
        $seller = $this->makeSeller();
        $listing = $this->makeListing($seller);
        Sanctum::actingAs($seller);
        $url = "/api/v1/seller/listings/{$listing->ulid}";

        $this->patchJson($url, ['prices' => [['license' => 'commercial', 'price_cents' => 2500]]])->assertOk()->assertJsonPath('data.prices.0.price_cents', 2500);
        $this->patchJson($url, ['title' => 'Outro título'])->assertStatus(422)->assertJsonValidationErrors('title');

        $this->postJson("$url/unpublish")->assertOk()->assertJsonPath('data.status', 'archived');
        $this->patchJson($url, ['title' => 'Outro título'])->assertOk(); // arquivada pode ser editada
    }

    public function test_admin_seller_publishes_directly_and_auto_approve_config_works(): void
    {
        $admin = $this->makeSeller([], []);
        $admin->forceFill(['is_platform_admin' => true])->save();
        $media = $this->realMediaFor($admin);
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid))->json('data.id');
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'published');

        config(['mediahub.marketplace.auto_approve' => true]);
        $seller = $this->makeSeller();
        $media2 = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);
        $id2 = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media2->ulid))->json('data.id');
        $this->postJson("/api/v1/seller/listings/{$id2}/submit")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_suspend_and_restore_by_moderator(): void
    {
        $listing = $this->makeListing();
        $admin = User::factory()->platformAdmin()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/admin/listings/{$listing->ulid}/suspend", ['reason' => 'Direitos autorais contestados'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->postJson("/api/v1/admin/listings/{$listing->ulid}/approve")->assertOk()->assertJsonPath('data.status', 'published'); // restaura
    }

    public function test_sellers_only_see_and_touch_their_own_listings(): void
    {
        $alice = $this->makeSeller();
        $bob = $this->makeSeller();
        $listing = $this->makeListing($alice);
        Sanctum::actingAs($bob);
        $url = "/api/v1/seller/listings/{$listing->ulid}";

        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['prices' => [['license' => 'commercial', 'price_cents' => 300]]])->assertNotFound();
        $this->postJson("$url/submit")->assertNotFound();
        $this->postJson("$url/unpublish")->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->getJson('/api/v1/seller/listings')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('commercial', $listing->fresh()->prices->first()->license->code);
        $this->assertSame(1500, $listing->fresh()->prices->first()->price_cents);
    }

    public function test_drafts_can_be_deleted_but_published_ones_cannot_and_media_is_locked(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);
        $id = $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid))->json('data.id');
        $this->postJson("/api/v1/seller/listings/{$id}/submit")->assertOk(); // em análise

        // mídia em análise não pode ser apagada da biblioteca
        $this->deleteJson("/api/v1/tenants/{$media->tenant->ulid}/media/{$media->ulid}")->assertStatus(422);
        $this->deleteJson("/api/v1/seller/listings/{$id}")->assertStatus(422);

        $this->postJson("/api/v1/seller/listings/{$id}/unpublish")->assertOk();
        $this->deleteJson("/api/v1/seller/listings/{$id}")->assertNoContent();
        $this->assertSame(0, Listing::withTrashed()->count());
    }

    public function test_html_in_text_fields_is_stripped(): void
    {
        $seller = $this->makeSeller();
        $media = $this->realMediaFor($seller);
        Sanctum::actingAs($seller);

        $this->postJson('/api/v1/seller/listings', $this->validListingPayload($media->ulid, ['title' => '<script>alert(1)</script>Praia linda', 'description' => '<img src=x onerror=1>Descrição ok de teste']))
            ->assertCreated()->assertJsonPath('data.title', 'alert(1)Praia linda')->assertJsonPath('data.description', 'Descrição ok de teste');
    }
}
