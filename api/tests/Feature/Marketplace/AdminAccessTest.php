<?php

namespace Tests\Feature\Marketplace;

use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_emails_in_the_server_config_are_admins_without_any_command(): void
    {
        config(['mediahub.platform_admin_emails' => ['dono@example.test']]);
        $owner = User::factory()->create(['email' => 'Dono@Example.test']); // maiúsculas não importam
        $other = User::factory()->create();

        Sanctum::actingAs($other);
        $this->getJson('/api/v1/admin/listings')->assertNotFound();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.is_admin', false);

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/listings')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.is_admin', true);
    }

    public function test_an_admin_promotes_and_demotes_others_from_the_interface(): void
    {
        $admin = User::factory()->create(['is_platform_admin' => true]);
        $maria = User::factory()->create(['name' => 'Maria Moderadora', 'email' => 'maria@example.test']);

        Sanctum::actingAs($maria);
        $this->getJson('/api/v1/admin/users')->assertNotFound();
        $this->patchJson("/api/v1/admin/users/{$maria->ulid}", ['is_admin' => true])->assertNotFound(); // ninguém se promove

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/users?q=maria')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_admin', false);
        $this->patchJson("/api/v1/admin/users/{$maria->ulid}", ['is_admin' => true])->assertOk()->assertJsonPath('data.is_admin', true);

        Sanctum::actingAs($maria->fresh());
        $this->getJson('/api/v1/admin/listings')->assertOk(); // já modera

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/admin/users/{$maria->ulid}", ['is_admin' => false])->assertOk()->assertJsonPath('data.is_admin', false);
        $this->patchJson("/api/v1/admin/users/{$admin->ulid}", ['is_admin' => false])->assertStatus(422); // não tira o próprio acesso
        $this->patchJson('/api/v1/admin/users/' . $maria->ulid, ['is_admin' => 'talvez'])->assertStatus(422);
        $this->assertStringNotContainsString('password', $this->getJson('/api/v1/admin/users')->getContent());
    }

    public function test_configured_admins_cannot_be_demoted_from_the_interface(): void
    {
        config(['mediahub.platform_admin_emails' => ['fixo@example.test']]);
        $fixed = User::factory()->create(['email' => 'fixo@example.test']);
        Sanctum::actingAs(User::factory()->create(['is_platform_admin' => true]));

        $this->getJson('/api/v1/admin/users?q=fixo')->assertOk()->assertJsonPath('data.0.fixed_admin', true);
        $this->patchJson("/api/v1/admin/users/{$fixed->ulid}", ['is_admin' => false])->assertStatus(422);
    }

    public function test_trusted_sellers_publish_directly_and_others_wait_for_review(): void
    {
        $normal = $this->makeSeller();
        $trusted = $this->makeSeller(['auto_approve' => true]);
        $a = $this->makeListing($normal, ['status' => 'draft', 'published_at' => null]);
        $b = $this->makeListing($trusted, ['status' => 'draft', 'published_at' => null]);

        Sanctum::actingAs($normal);
        $this->postJson("/api/v1/seller/listings/{$a->ulid}/submit")->assertOk();
        Sanctum::actingAs($trusted);
        $this->postJson("/api/v1/seller/listings/{$b->ulid}/submit")->assertOk();

        $this->assertSame('pending_review', Listing::find($a->id)->status->value);
        $this->assertSame('published', Listing::find($b->id)->status->value);
    }

    public function test_admin_turns_auto_approval_on_for_a_seller(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs(User::factory()->create(['is_platform_admin' => true]));

        $slug = $seller->sellerProfile->slug;
        $this->patchJson("/api/v1/admin/sellers/{$slug}", ['auto_approve' => true])->assertOk()->assertJsonPath('data.auto_approve', true);
        $this->getJson('/api/v1/admin/sellers')->assertOk()->assertJsonPath('data.0.auto_approve', true);
        $this->patchJson("/api/v1/admin/sellers/{$slug}", ['auto_approve' => 'sim'])->assertStatus(422);
    }
}
