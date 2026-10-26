<?php

namespace Tests\Feature\Galleries;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\TenantApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class PhotoUploadApiTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        config(['mediahub.galleries.timezone' => 'America/Sao_Paulo', 'app.frontend_url' => 'https://galeria.exemplo.test']);
    }

    private function key(User $owner, Tenant $tenant, bool $write): string
    {
        Sanctum::actingAs($owner);

        return $this->postJson("/api/v1/tenants/{$tenant->ulid}/api-keys", ['name' => $write ? 'Site do guia' : 'So leitura', 'can_write' => $write])->assertCreated()->json('data.key');
    }

    private function as(string $key): self
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $key, 'Accept' => 'application/json']);
    }

    private function jpeg(?string $bytes = null, string $name = 'foto.jpg'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($tmp, $bytes ?? $this->jpegBytes(900, 600));

        return new UploadedFile($tmp, $name, 'image/jpeg', null, true);
    }

    public function test_chave_so_de_leitura_nao_envia_fotos_nem_cria_galeria(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $read = $this->key($owner, $tenant, false);

        $this->as($read)->post('/api/v1/integration/photos', ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertForbidden();
        $this->as($read)->postJson('/api/v1/integration/gallery')->assertForbidden();
        $this->as($read)->getJson('/api/v1/integration/galleries')->assertOk(); // ler as galerias continua valendo
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
    }

    public function test_chave_com_envio_recebe_a_foto_no_projeto_dela_e_a_data_informada_vale(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);

        $res = $this->as($write)->post('/api/v1/integration/photos', [
            'file' => $this->jpeg(null, 'IMG_0001.jpg'), 'taken_at' => '2026-10-24T15:30:00-03:00', 'name' => 'Passeio no Arpoador',
        ], ['Accept' => 'application/json'])->assertCreated();

        $media = Media::withoutGlobalScope('tenant')->where('ulid', $res->json('data.id'))->firstOrFail();
        $this->assertSame($tenant->id, $media->tenant_id);
        $this->assertSame('Passeio no Arpoador', $media->display_name);
        $this->assertSame('2026-10-24 18:30:00', $media->taken_at->utc()->format('Y-m-d H:i:s')); // 15:30 BRT = 18:30 UTC
        $this->assertSame('ready', $media->fresh()->status->value); // fila "sync" nos testes: já processou
        $this->assertNotNull($media->variants()->where('kind', 'thumb')->first());
        $this->assertGreaterThan(0, $tenant->fresh()->storage_used_bytes);
        $this->assertStringNotContainsString('storage/app', $res->getContent());
    }

    public function test_sem_data_informada_vale_a_do_envio(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 12:00:00', 'UTC'));

        $id = $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->assertSame('2026-10-26 12:00:00', Media::withoutGlobalScope('tenant')->where('ulid', $id)->first()->taken_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_so_imagens_de_verdade_arquivo_disfarçado_e_acima_do_limite_sao_recusados(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);

        $texto = $this->jpeg("<?php echo 'oi'; ?>", 'foto.jpg'); // extensão jpg, conteúdo não é imagem
        $this->as($write)->post('/api/v1/integration/photos', ['file' => $texto], ['Accept' => 'application/json'])->assertStatus(422);

        $mp4 = tempnam(sys_get_temp_dir(), 'v'); file_put_contents($mp4, $this->mp4Bytes());
        $this->as($write)->post('/api/v1/integration/photos', ['file' => new UploadedFile($mp4, 'v.mp4', 'video/mp4', null, true)], ['Accept' => 'application/json'])->assertStatus(422);

        $this->as($write)->post('/api/v1/integration/photos', [], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg(), 'taken_at' => 'ontem de tarde'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('taken_at');
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
    }

    public function test_cota_do_projeto_e_respeitada(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);
        $tenant->forceFill(['storage_quota_bytes' => 1000])->save(); // menos que uma foto

        $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
    }

    public function test_pasta_de_outro_projeto_nao_pode_receber_a_foto(): void
    {
        [$ownerA, $tenantA] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        $keyA = $this->key($ownerA, $tenantA, true);
        Sanctum::actingAs($ownerB);
        $colB = $this->postJson("/api/v1/tenants/{$tenantB->ulid}/collections", ['name' => 'Do B'])->assertCreated()->json('data.id');

        $this->as($keyA)->post('/api/v1/integration/photos', ['file' => $this->jpeg(), 'collection' => $colB], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('collection');
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->count());
    }

    public function test_pasta_propria_recebe_a_foto_e_a_galeria_da_pasta_mostra(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);
        Sanctum::actingAs($owner);
        $col = $this->postJson("/api/v1/tenants/{$tenant->ulid}/collections", ['name' => 'Passeio 24/10'])->assertCreated()->json('data.id');
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC'));

        $url = $this->as($write)->postJson('/api/v1/integration/gallery', ['title' => 'Passeio 24/10', 'collection' => $col])->assertCreated()->json('data.url');
        $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg(), 'collection' => $col], ['Accept' => 'application/json'])->assertCreated();
        $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg(), 'collection' => $col], ['Accept' => 'application/json'])->assertCreated();

        $this->flushHeaders(); $this->app['auth']->forgetGuards();
        $list = $this->getJson('/api/v1/galleries/' . basename($url) . '/photos')->assertOk();
        $this->assertCount(2, $list->json('data'));
    }

    public function test_criar_galeria_pela_integracao_e_idempotente(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);

        $a = $this->as($write)->postJson('/api/v1/integration/gallery', ['title' => 'Minha galeria'])->assertCreated();
        $b = $this->as($write)->postJson('/api/v1/integration/gallery')->assertOk(); // já existia: devolve a mesma
        $this->assertSame($a->json('data.url'), $b->json('data.url'));
        $this->assertStringStartsWith('https://galeria.exemplo.test/g/', $a->json('data.url'));
        $this->assertFalse($a->json('data.allow_download'));
        $this->assertTrue($a->json('data.watermark'));

        $this->as($write)->getJson('/api/v1/integration/galleries')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, \App\Models\Gallery::withoutGlobalScope('tenant')->count());
    }

    public function test_upload_responde_com_o_link_da_galeria_quando_ela_existe(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $write = $this->key($owner, $tenant, true);
        $link = $this->as($write)->postJson('/api/v1/integration/gallery')->assertCreated()->json('data.url');

        $res = $this->as($write)->post('/api/v1/integration/photos', ['file' => $this->jpeg()], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame($link, $res->json('data.gallery_url'));
    }

    public function test_chave_com_envio_aparece_assim_na_lista_e_continua_presa_ao_projeto(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->key($owner, $tenant, true);
        $this->key($owner, $tenant, false);

        Sanctum::actingAs($owner);
        $list = $this->getJson("/api/v1/tenants/{$tenant->ulid}/api-keys")->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([true, false], array_column($list, 'can_write'));
        $this->assertSame(['read', 'read,write'], collect(TenantApiKey::orderBy('id')->pluck('scopes'))->sort()->values()->all());
    }
}
