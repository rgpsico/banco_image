<?php

namespace Tests\Feature\Media;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    public function test_chunked_image_upload_creates_media_variants_and_counts_quota(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes();

        $res = $this->uploadBytes($editor, $tenant, $bytes)->assertCreated();

        $res->assertJsonPath('data.type', 'image')
            ->assertJsonPath('data.status', 'ready') // fila "sync" nos testes
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.size_bytes', strlen($bytes))
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $this->assertNotNull($res->json('data.thumbnail_url'));
        $this->assertNotNull($res->json('data.preview_url'));
        $this->assertSame(strlen($bytes), $tenant->fresh()->storage_used_bytes);

        $media = Media::withoutGlobalScope('tenant')->firstOrFail();
        Storage::disk('media')->assertExists($media->path);
        $this->assertSame($bytes, Storage::disk('media')->get($media->path)); // original intacto
        $this->assertCount(2, $media->variants);
        $this->assertEmpty(Storage::disk('uploads')->allFiles()); // partes temporárias removidas

        $thumb = $media->variants->firstWhere('kind.value', 'thumb');
        $this->assertLessThanOrEqual(400, max($thumb->width, $thumb->height));
    }

    public function test_video_upload_is_accepted_by_real_content_and_has_stream_url(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);

        $res = $this->uploadBytes($editor, $tenant, $this->mp4Bytes(), 'clipe.mp4', 'video/mp4', 1500)->assertCreated();

        $res->assertJsonPath('data.type', 'video')->assertJsonPath('data.status', 'ready')->assertJsonPath('data.thumbnail_url', null);
        $this->assertNotNull($res->json('data.stream_url'));
    }

    public function test_file_with_fake_extension_is_rejected_by_content(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);

        // Um script PHP disfarçado de .jpg / image/jpeg
        $this->uploadBytes($editor, $tenant, "<?php system(\$_GET['x']); ?>" . str_repeat('A', 500), 'foto.jpg', 'image/jpeg', 300)
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
        $this->assertSame(0, $tenant->fresh()->storage_used_bytes);
        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }

    public function test_declared_type_must_match_the_real_content(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);

        // Declara vídeo, mas o conteúdo é uma imagem.
        $this->uploadBytes($editor, $tenant, $this->jpegBytes(), 'x.mp4', 'video/mp4')->assertStatus(422);
    }

    public function test_disallowed_types_are_refused_at_start(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        Sanctum::actingAs($editor);

        foreach (['image/svg+xml', 'application/pdf', 'text/html', 'application/x-php'] as $mime) {
            $this->postJson($this->base($tenant) . '/uploads', ['original_name' => 'a.bin', 'mime_type' => $mime, 'size_bytes' => 100, 'chunk_size' => 100])
                ->assertStatus(422)->assertJsonValidationErrors('file');
        }
    }

    public function test_size_limit_per_type_is_enforced(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        Sanctum::actingAs($editor);

        $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'enorme.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 26 * 1024 * 1024, 'chunk_size' => 5 * 1024 * 1024,
        ])->assertStatus(422)->assertJsonValidationErrors('size_bytes');
    }

    public function test_storage_quota_blocks_uploads_that_do_not_fit(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $tenant->update(['storage_quota_bytes' => 1000]);
        $bytes = $this->jpegBytes();
        Sanctum::actingAs($editor);

        $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 50000,
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_parallel_uploads_cannot_overflow_the_quota(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $a = $this->jpegBytes(400, 300);
        $b = $this->jpegBytes(400, 300);
        $tenant->update(['storage_quota_bytes' => max(strlen($a), strlen($b)) + 100]); // cabe um, não dois
        Sanctum::actingAs($editor);

        $start = fn ($bytes) => $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'p.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 500000,
        ])->assertCreated()->json('data.id'); // as duas sessões começam (cada uma cabe sozinha)

        $s1 = $start($a);
        $s2 = $start($b);
        $this->sendChunk($tenant, $s1, 0, $a)->assertOk();
        $this->sendChunk($tenant, $s2, 0, $b)->assertOk();

        $this->postJson($this->base($tenant) . "/uploads/{$s1}/complete")->assertCreated();
        $this->postJson($this->base($tenant) . "/uploads/{$s2}/complete")->assertStatus(422); // cota revalidada com lock

        $this->assertSame(1, Media::withoutGlobalScope('tenant')->count());
        $this->assertLessThanOrEqual($tenant->storage_quota_bytes, $tenant->fresh()->storage_used_bytes);
    }

    public function test_incomplete_upload_cannot_be_completed(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes();
        Sanctum::actingAs($editor);

        $id = $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 50000,
        ])->json('data.id');
        $this->sendChunk($tenant, $id, 0, substr($bytes, 0, 50000))->assertOk(); // falta o resto

        $this->postJson($this->base($tenant) . "/uploads/{$id}/complete")->assertStatus(422);
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
    }

    public function test_chunk_with_wrong_size_or_index_is_rejected(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes();
        Sanctum::actingAs($editor);

        $id = $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 50000,
        ])->json('data.id');

        $this->sendChunk($tenant, $id, 0, 'curto')->assertStatus(422);
        $this->sendChunk($tenant, $id, 999, substr($bytes, 0, 50000))->assertStatus(422);
    }

    public function test_viewer_cannot_upload_but_editor_can(): void
    {
        $tenant = Tenant::factory()->create();
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        Sanctum::actingAs($viewer);

        $this->postJson($this->base($tenant) . '/uploads', ['original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 100, 'chunk_size' => 100])
            ->assertForbidden();
    }

    public function test_upload_sessions_are_private_to_their_creator_and_project(): void
    {
        $tenantA = Tenant::factory()->create();
        [$alice] = $this->userWithRole(TenantRole::Editor, $tenantA);
        [$bob] = $this->userWithRole(TenantRole::Editor, $tenantA);
        [$carol, $tenantB] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes(100, 100);

        Sanctum::actingAs($alice);
        $id = $this->postJson($this->base($tenantA) . '/uploads', [
            'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 500000,
        ])->json('data.id');

        Sanctum::actingAs($bob); // colega do mesmo projeto
        $this->sendChunk($tenantA, $id, 0, $bytes)->assertNotFound();
        $this->postJson($this->base($tenantA) . "/uploads/{$id}/complete")->assertNotFound();

        Sanctum::actingAs($carol); // outro projeto
        $this->sendChunk($tenantB, $id, 0, $bytes)->assertNotFound();
    }

    public function test_filename_is_sanitized_and_storage_path_is_server_generated(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);

        $res = $this->uploadBytes($editor, $tenant, $this->pngBytes(), '../../etc/<b>passwd</b>.png', 'image/png')->assertCreated();

        $this->assertSame('passwd.png', $res->json('data.name'));
        $media = Media::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertStringStartsWith('tenants/' . $tenant->ulid . '/', $media->path);
        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringEndsWith('.png', $media->path); // extensão vem do conteúdo, não do nome
    }

    public function test_aborting_an_upload_removes_temporary_parts(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $bytes = $this->jpegBytes();
        Sanctum::actingAs($editor);
        $id = $this->postJson($this->base($tenant) . '/uploads', [
            'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => strlen($bytes), 'chunk_size' => 50000,
        ])->json('data.id');
        $this->sendChunk($tenant, $id, 0, substr($bytes, 0, 50000))->assertOk();
        $this->assertNotEmpty(Storage::disk('uploads')->allFiles());

        $this->deleteJson($this->base($tenant) . "/uploads/{$id}")->assertNoContent();

        $this->assertEmpty(Storage::disk('uploads')->allFiles());
    }
}
