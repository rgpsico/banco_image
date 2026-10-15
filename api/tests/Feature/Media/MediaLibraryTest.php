<?php

namespace Tests\Feature\Media;

use App\Enums\ListingStatus;
use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    private function seedMedia(Tenant $tenant, int $n, array $attrs = []): void
    {
        Media::factory()->count($n)->create(array_merge(['tenant_id' => $tenant->id], $attrs));
    }

    public function test_list_is_paginated_filtered_and_searchable(): void
    {
        [$viewer, $tenant] = $this->userWithRole(TenantRole::Viewer);
        $this->seedMedia($tenant, 30);
        Media::factory()->create(['tenant_id' => $tenant->id, 'display_name' => 'Praia do Pepê.jpg']);
        Media::factory()->create(['tenant_id' => $tenant->id, 'display_name' => 'surf_100%_real.jpg']);
        Media::factory()->create(['tenant_id' => $tenant->id, 'display_name' => 'clipe.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        Sanctum::actingAs($viewer);

        $this->getJson($this->base($tenant) . '/media')->assertOk()->assertJsonCount(24, 'data')->assertJsonPath('meta.total', 33);
        $this->getJson($this->base($tenant) . '/media?page=2')->assertOk()->assertJsonCount(9, 'data');
        $this->getJson($this->base($tenant) . '/media?search=praia')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base($tenant) . '/media?type=video')->assertOk()->assertJsonCount(1, 'data');
        // curinga do LIKE é tratado como texto comum
        $this->getJson($this->base($tenant) . '/media?search=' . urlencode('100%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base($tenant) . '/media?search=' . urlencode('%'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base($tenant) . '/media?type=pdf')->assertStatus(422);
    }

    public function test_list_can_filter_by_collection(): void
    {
        [$viewer, $tenant] = $this->userWithRole(TenantRole::Viewer);
        $a = Media::factory()->create(['tenant_id' => $tenant->id]);
        Media::factory()->create(['tenant_id' => $tenant->id]);
        $folder = Collection::factory()->create(['tenant_id' => $tenant->id]);
        $folder->media()->attach($a->id);
        Sanctum::actingAs($viewer);

        $this->getJson($this->base($tenant) . "/media?collection={$folder->ulid}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->ulid);
    }

    public function test_rename_sanitizes_and_requires_editor(): void
    {
        $tenant = Tenant::factory()->create();
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        $media = Media::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($viewer);
        $this->patchJson($this->base($tenant) . "/media/{$media->ulid}", ['display_name' => 'Novo'])->assertForbidden();

        Sanctum::actingAs($editor);
        $this->patchJson($this->base($tenant) . "/media/{$media->ulid}", ['display_name' => '<script>x</script>Pôr do sol.jpg'])
            ->assertOk()->assertJsonPath('data.name', 'xPôr do sol.jpg');
    }

    public function test_delete_removes_files_frees_quota_and_hides_the_media(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes();
        $id = $this->uploadBytes($editor, $tenant, $bytes)->json('data.id');
        $media = Media::withoutGlobalScope('tenant')->where('ulid', $id)->firstOrFail();
        $paths = $media->variants->pluck('path')->push($media->path);
        $this->assertSame(strlen($bytes), $tenant->fresh()->storage_used_bytes);

        $this->deleteJson($this->base($tenant) . "/media/{$id}")->assertNoContent();

        foreach ($paths as $p) {
            Storage::disk('media')->assertMissing($p);
        }
        $this->assertSame(0, $tenant->fresh()->storage_used_bytes);
        $this->getJson($this->base($tenant) . "/media/{$id}")->assertNotFound();
        $this->assertSame(0, \DB::table('media_variants')->count());
    }

    public function test_viewer_cannot_delete_and_published_media_cannot_be_deleted(): void
    {
        $tenant = Tenant::factory()->create();
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        $media = Media::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($viewer);
        $this->deleteJson($this->base($tenant) . "/media/{$media->ulid}")->assertForbidden();

        Listing::factory()->create(['media_id' => $media->id, 'tenant_id' => $tenant->id, 'status' => ListingStatus::Published]);
        Sanctum::actingAs($editor);
        $this->deleteJson($this->base($tenant) . "/media/{$media->ulid}")->assertStatus(422)->assertJsonValidationErrors('media');
        $this->assertNotNull($media->fresh());
    }

    public function test_media_of_another_project_is_invisible_everywhere(): void
    {
        [$alice, $surf] = $this->userWithRole(TenantRole::Owner);
        [, $pilates] = $this->userWithRole(TenantRole::Owner);
        $secret = Media::factory()->create(['tenant_id' => $pilates->id, 'display_name' => 'segredo.jpg']);
        Sanctum::actingAs($alice);
        $url = $this->base($surf) . "/media/{$secret->ulid}"; // projeto dela + id de mídia alheia

        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['display_name' => 'invadida'])->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->postJson($url . '/download-url')->assertNotFound();
        $this->getJson($this->base($surf) . '/media')->assertOk()->assertJsonCount(0, 'data');
        // e o projeto alheio em si
        $this->getJson($this->base($pilates) . '/media')->assertNotFound();

        $this->assertSame('segredo.jpg', $secret->fresh()->display_name);
    }

    public function test_signed_urls_serve_files_and_reject_tampering_or_expiry(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $created = $this->uploadBytes($editor, $tenant, $this->jpegBytes())->json('data');

        $thumb = $created['thumbnail_url'];
        $this->assertStringStartsWith('/api/v1/files/media/', $thumb); // relativa: funciona atrás de proxy

        $ok = $this->get($thumb);
        $ok->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("sandbox", $ok->headers->get('Content-Security-Policy'));

        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $thumb))->assertForbidden();   // assinatura adulterada
        $this->get(str_replace('/thumb', '/original', $thumb))->assertForbidden();                         // trocar o tipo de arquivo
        $this->get(strtok($thumb, '?'))->assertForbidden();                                               // sem assinatura

        $this->travel(2)->hours();
        $this->get($thumb)->assertForbidden();                                                             // expirada
    }

    public function test_download_url_requires_permission_forces_attachment_and_is_short_lived(): void
    {
        $tenant = Tenant::factory()->create();
        [$editor] = $this->userWithRole(TenantRole::Editor, $tenant);
        [$outsider] = $this->userWithRole(TenantRole::Owner); // dono de OUTRO projeto
        $id = $this->uploadBytes($editor, $tenant, $this->jpegBytes(), 'minha foto.jpg')->json('data.id');

        Sanctum::actingAs($outsider);
        $this->postJson($this->base($tenant) . "/media/{$id}/download-url")->assertNotFound();

        Sanctum::actingAs($editor);
        $url = $this->postJson($this->base($tenant) . "/media/{$id}/download-url")->assertOk()->json('data.url');

        $this->get($url)->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString('attachment', $this->get($url)->headers->get('Content-Disposition'));

        $this->travel(3)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_deleted_media_signed_url_returns_404(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $created = $this->uploadBytes($editor, $tenant, $this->jpegBytes())->json('data');
        $this->deleteJson($this->base($tenant) . "/media/{$created['id']}")->assertNoContent();

        $this->get($created['thumbnail_url'])->assertNotFound();
    }

    public function test_video_stream_url_supports_range_requests(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $created = $this->uploadBytes($editor, $tenant, $this->mp4Bytes(6000), 'v.mp4', 'video/mp4', 2000)->json('data');

        $this->get($created['stream_url'], ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Range');
    }

    public function test_tenant_scoped_queries_inside_jobs_need_explicit_context(): void
    {
        $tenant = Tenant::factory()->create();
        Media::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertSame(0, Media::count());
        $this->assertSame(1, app(CurrentTenant::class)->runAs($tenant, fn () => Media::count()));
    }
}
