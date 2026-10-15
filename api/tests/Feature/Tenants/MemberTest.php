<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_admin_can_add_a_member_by_email(): void
    {
        [$admin, $tenant] = $this->userWithRole(TenantRole::Admin);
        $newcomer = User::factory()->create(['email' => 'novo@example.test']);
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/tenants/{$tenant->ulid}/members", ['email' => 'NOVO@example.test', 'role' => 'editor'])
            ->assertCreated()->assertJsonPath('data.role', 'editor')->assertJsonPath('data.id', $newcomer->ulid);

        $this->assertSame(TenantRole::Editor, $newcomer->roleIn($tenant));
    }

    public function test_adding_unknown_or_duplicate_member_fails(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/v1/tenants/{$tenant->ulid}/members", ['email' => 'ninguem@example.test', 'role' => 'viewer'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/members", ['email' => $owner->email, 'role' => 'viewer'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_editor_and_viewer_cannot_manage_members(): void
    {
        $tenant = Tenant::factory()->create();
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        $target = User::factory()->create();

        foreach ([$editor, $viewer] as $user) {
            Sanctum::actingAs($user);
            $this->postJson("/api/v1/tenants/{$tenant->ulid}/members", ['email' => $target->email, 'role' => 'viewer'])->assertForbidden();
            $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$editor->ulid}", ['role' => 'admin'])->assertForbidden();
        }

        // ...mas podem ver a lista
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/members")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_cannot_assign_owner_nor_touch_an_owner(): void
    {
        $tenant = Tenant::factory()->create();
        [$admin] = $this->userWithRole(TenantRole::Admin, $tenant);
        [$owner] = $this->userWithRole(TenantRole::Owner, $tenant);
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        Sanctum::actingAs($admin);

        // escalonar alguém (ou a si mesmo) a owner
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$editor->ulid}", ['role' => 'owner'])->assertForbidden();
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$admin->ulid}", ['role' => 'owner'])->assertForbidden();
        // rebaixar ou remover um owner
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$owner->ulid}", ['role' => 'viewer'])->assertForbidden();
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/members/{$owner->ulid}")->assertForbidden();

        $this->assertSame(TenantRole::Owner, $owner->roleIn($tenant));
    }

    public function test_owner_can_change_roles_and_promote_another_owner(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$editor->ulid}", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('data.role', 'admin');
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$editor->ulid}", ['role' => 'owner'])->assertOk();
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/members/{$owner->ulid}", ['role' => 'admin'])->assertStatus(422);
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/members/{$owner->ulid}")->assertStatus(422);

        $this->assertSame(TenantRole::Owner, $owner->roleIn($tenant));
    }

    public function test_a_member_can_leave_the_tenant(): void
    {
        [$viewer, $tenant] = $this->userWithRole(TenantRole::Viewer);
        Sanctum::actingAs($viewer);

        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/members/{$viewer->ulid}")->assertNoContent();

        $this->assertNull($viewer->roleIn($tenant));
    }

    public function test_user_can_belong_to_multiple_tenants_with_different_roles(): void
    {
        $user = User::factory()->create();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $a->users()->attach($user->id, ['role' => 'owner']);
        $b->users()->attach($user->id, ['role' => 'viewer']);
        Sanctum::actingAs($user);

        $roles = collect($this->getJson('/api/v1/tenants')->assertOk()->json('data'))->pluck('role', 'id');

        $this->assertSame('owner', $roles[$a->ulid]);
        $this->assertSame('viewer', $roles[$b->ulid]);
    }
}
