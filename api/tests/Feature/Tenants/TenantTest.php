<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_creating_a_tenant_makes_the_creator_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tenants', ['name' => 'SurfGestão'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'SurfGestão')
            ->assertJsonPath('data.slug', 'surfgestao')
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame(TenantRole::Owner, $user->roleIn(Tenant::first()));
    }

    public function test_slug_is_made_unique_automatically(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/tenants', ['name' => 'Surf'])->assertCreated()->assertJsonPath('data.slug', 'surf');
        $this->postJson('/api/v1/tenants', ['name' => 'Surf'])->assertCreated()->assertJsonPath('data.slug', 'surf-2');
    }

    public function test_client_cannot_choose_its_own_role_or_owner(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/tenants', ['name' => 'X1', 'role' => 'viewer', 'created_by' => 999, 'storage_quota_bytes' => 99999999999])
            ->assertCreated()->assertJsonPath('data.role', 'owner');

        $tenant = Tenant::first();
        $this->assertSame($user->id, $tenant->created_by);
        $this->assertSame(5368709120, $tenant->storage_quota_bytes); // cota não é editável pelo cliente
    }

    public function test_index_lists_only_my_tenants(): void
    {
        [$user, $mine] = $this->userWithRole(TenantRole::Viewer);
        Tenant::factory()->create(); // de outra pessoa
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tenants')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->ulid);
    }

    public function test_permissions_by_role_on_update_and_delete(): void
    {
        $tenant = Tenant::factory()->create();
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        [$admin] = $this->userWithRole(TenantRole::Admin, $tenant);
        [$owner] = $this->userWithRole(TenantRole::Owner, $tenant);

        foreach ([$viewer, $editor] as $user) {
            Sanctum::actingAs($user);
            $this->patchJson("/api/v1/tenants/{$tenant->ulid}", ['name' => 'Novo'])->assertForbidden();
        }

        Sanctum::actingAs($admin);
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}", ['name' => 'Novo nome'])->assertOk()->assertJsonPath('data.name', 'Novo nome');
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}")->assertForbidden();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}")->assertNoContent();
        $this->assertSoftDeleted('tenants', ['id' => $tenant->id]);
    }

    public function test_responses_never_expose_internal_ids_or_paths(): void
    {
        [$user, $tenant] = $this->userWithRole(TenantRole::Owner);
        Sanctum::actingAs($user);

        $json = $this->getJson("/api/v1/tenants/{$tenant->ulid}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('created_by', $json);
        $this->assertSame($tenant->ulid, $json['id']);
        $this->assertNotSame($tenant->id, $json['id']);
    }
}
