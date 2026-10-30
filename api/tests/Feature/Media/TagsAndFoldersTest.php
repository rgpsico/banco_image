<?php

namespace Tests\Feature\Media;

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Media;
use App\Models\TenantApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class TagsAndFoldersTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    private function photo($user, $tenant, string $name): string
    {
        return $this->uploadBytes($user, $tenant, $this->jpegBytes(400, 300), $name)->assertCreated()->json('data.id');
    }

    public function test_editor_sets_tags_and_search_matches_name_or_tags_with_all_words(): void
    {
        [$user, $tenant] = $this->userWithRole(TenantRole::Editor);
        $burger = $this->photo($user, $tenant, 'foto1.jpg');
        $pizza = $this->photo($user, $tenant, 'foto2.jpg');
        Sanctum::actingAs($user);

        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$burger", ['display_name' => 'Hambúrguer 1', 'tags' => ['Burger', ' lanche ', 'burger', 'XIS']])
            ->assertOk()->assertJsonPath('data.tags', ['burger', 'lanche', 'xis']);
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$pizza", ['display_name' => 'Pizza 1', 'tags' => 'calabresa, forno'])
            ->assertOk()->assertJsonPath('data.tags', ['calabresa', 'forno']);

        $ids = fn (string $q) => collect($this->getJson("/api/v1/tenants/{$tenant->ulid}/media?search=" . urlencode($q))->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$burger], $ids('xis'));            // só pela tag
        $this->assertSame([$burger], $ids('hamburguer'));     // pelo nome, sem acento
        $this->assertSame([$burger], $ids('burger lanche'));  // duas palavras, ambas nas tags
        $this->assertSame([], $ids('burger calabresa'));      // palavras de fotos diferentes não casam
    }

    public function test_viewer_cannot_change_tags_and_tags_are_validated(): void
    {
        [$editor, $tenant] = $this->userWithRole(TenantRole::Editor);
        $id = $this->photo($editor, $tenant, 'a.jpg');
        [$viewer] = $this->userWithRole(TenantRole::Viewer, $tenant);

        Sanctum::actingAs($viewer);
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$id", ['tags' => ['x']])->assertForbidden();

        Sanctum::actingAs($editor);
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$id", ['tags' => array_fill(0, 21, 'a')])->assertUnprocessable();
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$id", ['tags' => [str_repeat('x', 31)]])->assertUnprocessable();
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/media/$id", [])->assertUnprocessable();
    }

    public function test_filtering_a_folder_includes_subfolders_and_accepts_several_folders(): void
    {
        [$user, $tenant] = $this->userWithRole(TenantRole::Owner);
        $a = $this->photo($user, $tenant, 'a.jpg'); $b = $this->photo($user, $tenant, 'b.jpg'); $c = $this->photo($user, $tenant, 'c.jpg');
        Sanctum::actingAs($user);
        $base = "/api/v1/tenants/{$tenant->ulid}";

        $comidas = $this->postJson("$base/collections", ['name' => 'Comidas'])->json('data.id');
        $lanches = $this->postJson("$base/collections", ['name' => 'Lanches', 'parent_id' => $comidas])->json('data.id');
        $bebidas = $this->postJson("$base/collections", ['name' => 'Bebidas'])->json('data.id');
        $this->postJson("$base/collections/$comidas/media", ['media' => [$a]])->assertOk();
        $this->postJson("$base/collections/$lanches/media", ['media' => [$b]])->assertOk();
        $this->postJson("$base/collections/$bebidas/media", ['media' => [$c]])->assertOk();

        $ids = fn (string $f) => collect($this->getJson("$base/media?collection=$f")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$a, $b])->sort()->values()->all(), $ids($comidas)); // pasta pai traz as subpastas
        $this->assertSame([$b], $ids($lanches));
        $this->assertSame(collect([$a, $b, $c])->sort()->values()->all(), $ids("$comidas,$bebidas"));
        $this->getJson("$base/media?collection=nao-e-ulid")->assertUnprocessable();
    }

    public function test_integration_api_returns_tags_and_filters_by_folder_tree(): void
    {
        [$user, $tenant] = $this->userWithRole(TenantRole::Owner);
        $a = $this->photo($user, $tenant, 'a.jpg');
        Sanctum::actingAs($user);
        $base = "/api/v1/tenants/{$tenant->ulid}";
        $pai = $this->postJson("$base/collections", ['name' => 'Comidas'])->json('data.id');
        $filha = $this->postJson("$base/collections", ['name' => 'Lanches', 'parent_id' => $pai])->json('data.id');
        $this->postJson("$base/collections/$filha/media", ['media' => [$a]])->assertOk();
        $this->patchJson("$base/media/$a", ['tags' => ['burger']])->assertOk();
        // o processamento das miniaturas é assíncrono; a API de integração só lista mídia pronta
        Media::withoutGlobalScope('tenant')->where('ulid', $a)->update(['status' => 'ready']);

        [$key, $plain] = TenantApiKey::issue($tenant, 'teste', $user, null);
        $res = $this->withHeader('Authorization', "Bearer $plain")->getJson("/api/v1/integration/media?collection=$pai&search=burger")->assertOk();
        $this->assertSame([$a], collect($res->json('data'))->pluck('id')->all());
        $this->assertSame(['burger'], $res->json('data.0.tags'));
    }
}
