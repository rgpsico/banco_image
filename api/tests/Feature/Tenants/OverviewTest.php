<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    public function test_visao_geral_conta_so_o_proprio_projeto_e_qualquer_membro_ve(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        $this->uploadBytes($owner, $tenant, $this->jpegBytes(900, 600), 'a.jpg')->assertCreated();
        $this->uploadBytes($owner, $tenant, $this->jpegBytes(900, 600), 'b.jpg')->assertCreated();
        $this->uploadBytes($ownerB, $tenantB, $this->jpegBytes(900, 600), 'do-outro.jpg')->assertCreated();

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Passeio'])->assertCreated();
        $res = $this->getJson("/api/v1/tenants/{$tenant->ulid}/overview")->assertOk();
        $res->assertJsonPath('data.photos', 2)->assertJsonPath('data.videos', 0)->assertJsonPath('data.galleries', 1)->assertJsonPath('data.gallery_views', 0);
        $this->assertGreaterThan(0, $res->json('data.storage.used_bytes'));
        $this->assertNotNull($res->json('data.last_upload_at'));

        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/overview")->assertOk()->assertJsonPath('data.photos', 2);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/overview")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/overview")->assertUnauthorized();
    }

    public function test_galeria_no_painel_traz_ate_4_miniaturas_so_do_proprio_projeto(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        for ($i = 0; $i < 5; $i++) {
            $this->uploadBytes($owner, $tenant, $this->jpegBytes(900, 600), "f{$i}.jpg")->assertCreated();
        }
        $this->uploadBytes($ownerB, $tenantB, $this->jpegBytes(900, 600), 'outro.jpg')->assertCreated();

        Sanctum::actingAs($owner);
        $g = $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Com fotos'])->assertCreated()->json('data');

        $this->assertCount(4, $g['thumbs']); // no máximo 4
        $this->assertSame(5, $g['photos_count']);
        foreach ($g['thumbs'] as $url) {
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringNotContainsString('storage/app', $url);
        }
        $this->assertSame(1, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenantB->id)->count());
    }
}
