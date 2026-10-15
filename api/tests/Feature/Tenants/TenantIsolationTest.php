<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Isolamento multi-tenant: um projeto jamais enxerga, lista ou altera dados de outro. */
class TenantIsolationTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Rota de teste que usa o middleware de tenant por contexto (X-Tenant), como as rotas de mídia farão.
        Route::middleware(['api', 'auth:sanctum', 'tenant'])->prefix('api/v1/_t')->group(function () {
            Route::get('media', fn () => Media::query()->pluck('display_name'));
            Route::post('media', function (\Illuminate\Http\Request $request) {
                // tenant_id vindo do front NÃO é considerado: o servidor usa o contexto autenticado.
                $m = Media::factory()->make(['display_name' => 'novo', 'tenant_id' => $request->input('tenant_id')]);
                $m->tenant_id = null;
                $m->save();

                return response()->json(['tenant' => $m->tenant->ulid], 201);
            });
        });
    }

    public function test_a_user_cannot_read_update_or_delete_another_tenant(): void
    {
        [$alice, $surf] = $this->userWithRole(TenantRole::Owner);
        [, $pilates] = $this->userWithRole(TenantRole::Owner); // outro dono, outro projeto
        Sanctum::actingAs($alice);

        // 404 (e não 403) para não revelar que o projeto existe
        $this->getJson("/api/v1/tenants/{$pilates->ulid}")->assertNotFound();
        $this->patchJson("/api/v1/tenants/{$pilates->ulid}", ['name' => 'Invadido'])->assertNotFound();
        $this->deleteJson("/api/v1/tenants/{$pilates->ulid}")->assertNotFound();
        $this->getJson("/api/v1/tenants/{$pilates->ulid}/members")->assertNotFound();
        $this->postJson("/api/v1/tenants/{$pilates->ulid}/members", ['email' => $alice->email, 'role' => 'owner'])->assertNotFound();

        $this->assertNotSame('Invadido', $pilates->fresh()->name);
        $this->assertNull($alice->roleIn($pilates)); // não conseguiu se autoadicionar
        $this->assertNotNull($surf->fresh());
    }

    public function test_x_tenant_header_only_selects_among_my_tenants(): void
    {
        [$alice, $surf] = $this->userWithRole(TenantRole::Viewer);
        $pilates = Tenant::factory()->create();
        Media::factory()->create(['tenant_id' => $surf->id, 'display_name' => 'surf-1.jpg']);
        Media::factory()->create(['tenant_id' => $pilates->id, 'display_name' => 'segredo-pilates.jpg']);
        Sanctum::actingAs($alice);

        $this->withHeader('X-Tenant', $surf->ulid)->getJson('/api/v1/_t/media')
            ->assertOk()->assertExactJson(['surf-1.jpg']);

        // forjar o header de um projeto do qual não sou membro
        $this->withHeader('X-Tenant', $pilates->ulid)->getJson('/api/v1/_t/media')->assertNotFound();
        // sem header
        $this->withoutHeader('X-Tenant')->getJson('/api/v1/_t/media')->assertStatus(400);
    }

    public function test_listing_media_never_returns_rows_of_other_tenants(): void
    {
        [$alice, $surf] = $this->userWithRole(TenantRole::Editor);
        $other = Tenant::factory()->create();
        Media::factory()->count(3)->create(['tenant_id' => $surf->id]);
        Media::factory()->count(5)->create(['tenant_id' => $other->id]);
        Sanctum::actingAs($alice);

        $this->withHeader('X-Tenant', $surf->ulid)->getJson('/api/v1/_t/media')->assertOk()->assertJsonCount(3);
    }

    public function test_tenant_id_sent_by_the_client_is_ignored_on_create(): void
    {
        [$alice, $surf] = $this->userWithRole(TenantRole::Editor);
        $other = Tenant::factory()->create();
        Sanctum::actingAs($alice);

        $this->withHeader('X-Tenant', $surf->ulid)
            ->postJson('/api/v1/_t/media', ['tenant_id' => $other->id])
            ->assertCreated()->assertJsonPath('tenant', $surf->ulid);

        $this->assertSame(0, Media::withoutGlobalScope('tenant')->where('tenant_id', $other->id)->count());
    }

    public function test_tenant_scoped_models_fail_closed_without_context(): void
    {
        $tenant = Tenant::factory()->create();
        Media::factory()->create(['tenant_id' => $tenant->id]);
        Collection::factory()->create(['tenant_id' => $tenant->id]);

        // sem tenant definido e sem bypass explícito: nada é retornado (nunca "tudo")
        $this->assertSame(0, Media::count());
        $this->assertSame(0, Collection::count());

        app(CurrentTenant::class)->runAs($tenant, function () {
            $this->assertSame(1, Media::count());
            $this->assertSame(1, Collection::count());
        });

        $this->assertSame(1, app(CurrentTenant::class)->withoutTenancy(fn () => Media::count()));
    }

    public function test_global_scope_blocks_lookup_by_id_across_tenants(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $mediaB = Media::factory()->create(['tenant_id' => $b->id]);

        app(CurrentTenant::class)->runAs($a, function () use ($mediaB) {
            $this->assertNull(Media::where('ulid', $mediaB->ulid)->first());
            $this->assertNull(Media::find($mediaB->id));
        });
    }

    public function test_media_serialization_hides_internal_fields(): void
    {
        $media = Media::factory()->create();

        $array = $media->toArray();

        foreach (['id', 'path', 'disk', 'tenant_id', 'checksum_sha256', 'uploaded_by'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $array);
        }
        $this->assertArrayHasKey('ulid', $array);
    }

    public function test_deleted_tenant_is_no_longer_reachable(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $tenant->delete();
        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/tenants/{$tenant->ulid}")->assertNotFound();
        $this->withHeader('X-Tenant', $tenant->ulid)->getJson('/api/v1/_t/media')->assertNotFound();
    }
}
