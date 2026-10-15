<?php

namespace Tests\Feature\Tenants;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\TenantApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
    }

    private function createKey($user, $tenant, array $body = ['name' => 'SurfGestão']): array
    {
        Sanctum::actingAs($user);
        $res = $this->postJson("/api/v1/tenants/{$tenant->ulid}/api-keys", $body)->assertCreated();

        return [$res->json('data.key'), $res->json('data.id'), $res];
    }

    private function noKey(): self
    {
        $this->flushHeaders(); // withHeaders() é "pegajoso" entre chamadas do mesmo teste
        $this->app['auth']->forgetGuards();

        return $this;
    }

    private function asKey(string $key): self
    {
        $this->noKey();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json']);
    }

    private function upload($user, $tenant, string $name = 'onda.jpg'): string
    {
        return $this->uploadBytes($user, $tenant, $this->jpegBytes(900, 600), $name)->assertCreated()->json('data.id');
    }

    public function test_the_full_key_is_shown_once_and_only_its_hash_is_stored(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$key, $id, $res] = $this->createKey($owner, $tenant);

        $this->assertStringStartsWith('mhk_', $key);
        $this->assertGreaterThan(40, strlen($key));
        $this->assertSame(substr($key, 0, 12), $res->json('data.prefix'));
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));

        $row = TenantApiKey::first();
        $this->assertSame(hash('sha256', $key), $row->key_hash);
        $this->assertStringNotContainsString($key, json_encode($row->getAttributes())); // o texto puro não está no banco

        // a listagem nunca devolve a chave
        $list = $this->getJson("/api/v1/tenants/{$tenant->ulid}/api-keys")->assertOk();
        $this->assertStringNotContainsString($key, $list->getContent());
        $this->assertStringNotContainsString('key_hash', $list->getContent());
        $list->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.created_by', $owner->name);
    }

    public function test_only_project_admins_manage_keys(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [, $id] = $this->createKey($owner, $tenant);

        foreach ([TenantRole::Editor, TenantRole::Viewer] as $role) {
            [$member] = $this->userWithRole($role, $tenant);
            Sanctum::actingAs($member);
            $this->getJson("/api/v1/tenants/{$tenant->ulid}/api-keys")->assertForbidden();
            $this->postJson("/api/v1/tenants/{$tenant->ulid}/api-keys", ['name' => 'x1'])->assertForbidden();
            $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/api-keys/{$id}")->assertForbidden();
        }

        // quem não é membro nem sabe que o projeto existe
        Sanctum::actingAs(\App\Models\User::factory()->create());
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/api-keys")->assertNotFound();
    }

    public function test_a_key_reads_only_its_own_project(): void
    {
        [$ownerA, $tenantA] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        $mediaA = $this->upload($ownerA, $tenantA, 'surf.jpg');
        $mediaB = $this->upload($ownerB, $tenantB, 'privada-do-outro.jpg');
        [$keyA] = $this->createKey($ownerA, $tenantA);

        $list = $this->asKey($keyA)->getJson('/api/v1/integration/media')->assertOk();
        $this->assertSame([$mediaA], array_column($list->json('data'), 'id'));
        $this->assertStringNotContainsString('privada-do-outro', $list->getContent());

        $this->asKey($keyA)->getJson("/api/v1/integration/media/{$mediaA}")->assertOk()->assertJsonPath('data.name', 'surf.jpg');
        $this->asKey($keyA)->getJson("/api/v1/integration/media/{$mediaB}")->assertNotFound(); // de outro projeto
        $this->asKey($keyA)->getJson("/api/v1/integration/media/{$mediaB}/file/original")->assertNotFound();
        $this->asKey($keyA)->getJson('/api/v1/integration/me')->assertOk()->assertJsonPath('data.project.name', $tenantA->name)->assertJsonPath('data.access', 'read-only');
    }

    public function test_urls_are_absolute_signed_and_the_file_comes_back_identical(): void
    {
        config(['app.url' => 'https://banco.exemplo.test/']);
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $bytes = $this->jpegBytes(900, 600);
        $id = $this->uploadBytes($owner, $tenant, $bytes, 'foto.jpg')->assertCreated()->json('data.id');
        [$key] = $this->createKey($owner, $tenant);

        $item = $this->asKey($key)->getJson("/api/v1/integration/media/{$id}?url_ttl=300")->assertOk()->json('data');
        $this->assertStringStartsWith('https://banco.exemplo.test/api/v1/files/media/', $item['urls']['thumbnail']); // APP_URL, não o host interno
        $this->assertStringContainsString('signature=', $item['urls']['original']);
        $this->assertNotNull($item['urls']['preview']);
        $this->assertStringNotContainsString('storage/app', json_encode($item)); // caminho interno nunca sai

        // o link assinado funciona sem chave nenhuma (é para o <img> do navegador) e entrega os mesmos bytes
        $path = parse_url($item['urls']['original'], PHP_URL_PATH) . '?' . parse_url($item['urls']['original'], PHP_URL_QUERY);
        $this->noKey()->get($path)->assertOk();

        // já o endpoint de arquivo exige a chave
        $this->noKey()->getJson("/api/v1/integration/media/{$id}/file/original")->assertUnauthorized();
        $file = $this->asKey($key)->get("/api/v1/integration/media/{$id}/file/original")->assertOk();
        $this->assertSame($bytes, file_get_contents($file->baseResponse->getFile()->getPathname()));
        $this->asKey($key)->get("/api/v1/integration/media/{$id}/file/thumb")->assertOk();
        $this->asKey($key)->get("/api/v1/integration/media/{$id}/file/download")->assertNotFound(); // só thumb/preview/original
    }

    public function test_invalid_missing_revoked_and_expired_keys_are_rejected(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$key, $id] = $this->createKey($owner, $tenant);

        $this->noKey()->getJson('/api/v1/integration/media')->assertUnauthorized();
        $this->asKey('mhk_' . str_repeat('x', 48))->getJson('/api/v1/integration/media')->assertUnauthorized();
        $this->asKey('qualquercoisa')->getJson('/api/v1/integration/media')->assertUnauthorized();
        $this->asKey($key . 'x')->getJson('/api/v1/integration/media')->assertUnauthorized();
        $this->noKey()->getJson('/api/v1/integration/media?api_key=' . $key)->assertUnauthorized(); // na URL nunca vale

        // X-Api-Key também funciona
        $this->noKey()->withHeaders(['X-Api-Key' => $key])->getJson('/api/v1/integration/media')->assertOk();

        // expirada
        TenantApiKey::first()->update(['expires_at' => now()->subMinute()]);
        $this->asKey($key)->getJson('/api/v1/integration/media')->assertUnauthorized();
        TenantApiKey::first()->update(['expires_at' => null]);
        $this->asKey($key)->getJson('/api/v1/integration/media')->assertOk();

        // revogada
        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/api-keys/{$id}")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->asKey($key)->getJson('/api/v1/integration/media')->assertUnauthorized();

        // projeto excluído também corta o acesso
        [$key2] = $this->createKey($owner, $tenant, ['name' => 'Outra chave']);
        $tenant->delete();
        $this->asKey($key2)->getJson('/api/v1/integration/media')->assertUnauthorized();
    }

    public function test_a_key_is_read_only_and_cannot_reach_anything_else(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $id = $this->upload($owner, $tenant);
        [$key] = $this->createKey($owner, $tenant);

        $h = $this->asKey($key);
        $h->postJson("/api/v1/tenants/{$tenant->ulid}/uploads", ['original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'chunk_size' => 10])->assertUnauthorized();
        $this->asKey($key)->deleteJson("/api/v1/tenants/{$tenant->ulid}/media/{$id}")->assertUnauthorized();
        $this->asKey($key)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->asKey($key)->getJson("/api/v1/tenants/{$tenant->ulid}/api-keys")->assertUnauthorized(); // chave não cria chave
        $this->asKey($key)->postJson('/api/v1/integration/media', [])->assertStatus(405);
        $this->assertNotNull(Media::withoutGlobalScope('tenant')->where('ulid', $id)->first());
    }

    public function test_filters_collections_and_pagination(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->upload($owner, $tenant, 'surf-arpoador.jpg');
        $this->upload($owner, $tenant, 'trilha-morro.jpg');
        [$key] = $this->createKey($owner, $tenant);

        Sanctum::actingAs($owner);
        $col = $this->postJson("/api/v1/tenants/{$tenant->ulid}/collections", ['name' => 'Surf'])->assertCreated()->json('data.id');
        $mid = Media::withoutGlobalScope('tenant')->where('display_name', 'surf-arpoador.jpg')->value('ulid');
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/collections/{$col}/media", ['media' => [$mid]])->assertSuccessful();

        $this->asKey($key)->getJson('/api/v1/integration/media?search=surf')->assertOk()->assertJsonCount(1, 'data');
        $this->asKey($key)->getJson("/api/v1/integration/media?collection={$col}")->assertOk()->assertJsonCount(1, 'data');
        $this->asKey($key)->getJson('/api/v1/integration/media?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->asKey($key)->getJson('/api/v1/integration/media?per_page=1000')->assertStatus(422);
        $this->asKey($key)->getJson('/api/v1/integration/media?url_ttl=5')->assertStatus(422);
        $this->asKey($key)->getJson('/api/v1/integration/collections')->assertOk()->assertJsonPath('data.0.name', 'Surf')->assertJsonPath('data.0.media_count', 1);
    }

    public function test_limit_of_active_keys_and_last_use_tracking(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$first] = $this->createKey($owner, $tenant, ['name' => 'Primeira', 'expires_in_days' => 30]);

        $this->assertNull(TenantApiKey::first()->last_used_at);
        $this->asKey($first)->getJson('/api/v1/integration/me')->assertOk();
        $this->assertNotNull(TenantApiKey::first()->last_used_at);
        $this->assertTrue(TenantApiKey::first()->expires_at->isFuture());

        for ($i = 2; $i <= TenantApiKey::MAX_ACTIVE_PER_TENANT; $i++) {
            $this->createKey($owner, $tenant, ['name' => "Chave {$i}"]);
        }
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/api-keys", ['name' => 'Excedente'])->assertStatus(422);
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/api-keys", ['name' => ''])->assertStatus(422);
    }
}
