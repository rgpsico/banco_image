<?php

namespace Tests\Feature\Media;

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    public function test_create_nested_folders_with_unique_slugs_among_siblings(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        Sanctum::actingAs($editor);
        $url = $this->base($tenant) . '/collections';

        $root = $this->postJson($url, ['name' => 'Campanhas'])->assertCreated()->assertJsonPath('data.slug', 'campanhas')->json('data');
        $this->postJson($url, ['name' => 'Campanhas'])->assertCreated()->assertJsonPath('data.slug', 'campanhas-2'); // irmã
        $child = $this->postJson($url, ['name' => 'Campanhas', 'parent_id' => $root['id']])
            ->assertCreated()->assertJsonPath('data.slug', 'campanhas')->assertJsonPath('data.parent_id', $root['id'])->json('data'); // outro pai: pode repetir

        $this->assertNotSame($root['id'], $child['id']);
        $this->getJson($url)->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_cannot_move_a_folder_into_itself_or_its_descendants(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $a = Collection::factory()->create(['tenant_id' => $tenant->id]);
        $b = Collection::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $a->id]);
        $c = Collection::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $b->id]);
        Sanctum::actingAs($editor);

        $this->patchJson($this->base($tenant) . "/collections/{$a->ulid}", ['parent_id' => $c->ulid])->assertStatus(422);
        $this->patchJson($this->base($tenant) . "/collections/{$a->ulid}", ['parent_id' => $a->ulid])->assertStatus(422);
        $this->patchJson($this->base($tenant) . "/collections/{$c->ulid}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
    }

    public function test_parent_from_another_project_is_refused(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $foreign = Collection::factory()->create(); // outro projeto
        Sanctum::actingAs($editor);

        $this->postJson($this->base($tenant) . '/collections', ['name' => 'X', 'parent_id' => $foreign->ulid])
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }

    public function test_attach_and_detach_media_and_counts(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $folder = Collection::factory()->create(['tenant_id' => $tenant->id]);
        $media = Media::factory()->count(3)->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($editor);

        $this->postJson($this->base($tenant) . "/collections/{$folder->ulid}/media", ['media' => $media->take(2)->pluck('ulid')->all()])
            ->assertOk()->assertJsonPath('data.media_count', 2);
        $this->postJson($this->base($tenant) . "/collections/{$folder->ulid}/media", ['media' => $media->take(2)->pluck('ulid')->all()])
            ->assertOk()->assertJsonPath('data.media_count', 2); // idempotente
        $this->getJson($this->base($tenant) . '/collections')->assertJsonPath('data.0.media_count', 2);

        $this->deleteJson($this->base($tenant) . "/collections/{$folder->ulid}/media/{$media[0]->ulid}")->assertNoContent();
        $this->assertSame(1, $folder->media()->count());
    }

    public function test_cannot_attach_media_from_another_project(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $folder = Collection::factory()->create(['tenant_id' => $tenant->id]);
        $mine = Media::factory()->create(['tenant_id' => $tenant->id]);
        $foreign = Media::factory()->create(); // outro projeto
        Sanctum::actingAs($editor);

        $this->postJson($this->base($tenant) . "/collections/{$folder->ulid}/media", ['media' => [$mine->ulid, $foreign->ulid]])
            ->assertStatus(422)->assertJsonValidationErrors('media');

        $this->assertSame(0, $folder->media()->count()); // nada é anexado (tudo ou nada)
    }

    public function test_deleting_a_folder_keeps_media_and_lifts_children(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $root = Collection::factory()->create(['tenant_id' => $tenant->id]);
        $mid = Collection::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $root->id]);
        $leaf = Collection::factory()->create(['tenant_id' => $tenant->id, 'parent_id' => $mid->id]);
        $media = Media::factory()->create(['tenant_id' => $tenant->id]);
        $mid->media()->attach($media->id);
        Sanctum::actingAs($editor);

        $this->deleteJson($this->base($tenant) . "/collections/{$mid->ulid}")->assertNoContent();

        $this->assertSame($root->id, $leaf->fresh()->parent_id);
        $this->assertNotNull($media->fresh()); // a mídia continua na biblioteca
        $this->assertSoftDeleted('collections', ['id' => $mid->id]);
    }

    public function test_permissions_and_cross_project_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);
        [$alice, $other] = $this->userWithRole(TenantRole::Owner);
        $folder = Collection::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($viewer);
        $this->getJson($this->base($tenant) . '/collections')->assertOk();                                                // pode ver
        $this->postJson($this->base($tenant) . '/collections', ['name' => 'Nova'])->assertForbidden();                    // não pode criar
        $this->patchJson($this->base($tenant) . "/collections/{$folder->ulid}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson($this->base($tenant) . "/collections/{$folder->ulid}")->assertForbidden();

        Sanctum::actingAs($alice); // dona de outro projeto
        $this->getJson($this->base($tenant) . '/collections')->assertNotFound();
        $this->patchJson($this->base($other) . "/collections/{$folder->ulid}", ['name' => 'Invadida'])->assertNotFound();
        $this->deleteJson($this->base($other) . "/collections/{$folder->ulid}")->assertNotFound();
        $this->assertNotSame('Invadida', $folder->fresh()->name);
    }
}
