<?php

namespace Tests\Feature\Galleries;

use App\Enums\TenantRole;
use App\Models\Gallery;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class GalleryTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        config(['mediahub.galleries.timezone' => 'America/Sao_Paulo', 'app.frontend_url' => 'https://galeria.exemplo.test']);
    }

    /** Foto REAL (com miniaturas) e com a data em que foi "tirada" definida pelo teste. */
    private function photo(User $user, Tenant $tenant, string $takenAtUtc, string $name = 'foto.jpg'): Media
    {
        $id = $this->uploadBytes($user, $tenant, $this->jpegBytes(900, 600), $name)->assertCreated()->json('data.id');
        $m = Media::withoutGlobalScope('tenant')->where('ulid', $id)->firstOrFail();
        $m->forceFill(['taken_at' => $takenAtUtc])->save();

        return $m->fresh();
    }

    private function createGallery(User $owner, Tenant $tenant, array $body = []): array
    {
        Sanctum::actingAs($owner);

        return $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", array_merge(['title' => 'Passeio de domingo'], $body))->assertCreated()->json('data');
    }

    private function tokenOf(array $gallery): string
    {
        return basename($gallery['public_url']);
    }

    public function test_admin_cria_galeria_com_padroes_seguros_e_so_admin_gerencia(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $g = $this->createGallery($owner, $tenant);

        $this->assertStringStartsWith('https://galeria.exemplo.test/g/', $g['public_url']);
        $this->assertGreaterThanOrEqual(40, strlen($this->tokenOf($g)));
        $this->assertFalse($g['allow_download']); // download desligado por padrão
        $this->assertTrue($g['watermark']);       // marca d'água ligada por padrão
        $this->assertTrue($g['is_active']);

        foreach ([TenantRole::Editor, TenantRole::Viewer] as $role) {
            [$m] = $this->userWithRole($role, $tenant);
            Sanctum::actingAs($m);
            $this->getJson("/api/v1/tenants/{$tenant->ulid}/galleries")->assertForbidden();
            $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Invasora'])->assertForbidden();
            $this->patchJson("/api/v1/tenants/{$tenant->ulid}/galleries/{$g['id']}", ['title' => 'x1'])->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create()); // quem nem é do projeto
        $this->getJson("/api/v1/tenants/{$tenant->ulid}/galleries")->assertNotFound();
    }

    public function test_galeria_de_um_projeto_nao_aparece_nem_se_altera_por_outro(): void
    {
        [$ownerA, $tenantA] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        $gA = $this->createGallery($ownerA, $tenantA, ['title' => 'Galeria A']);

        Sanctum::actingAs($ownerB);
        $this->getJson("/api/v1/tenants/{$tenantB->ulid}/galleries")->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/tenants/{$tenantB->ulid}/galleries/{$gA['id']}", ['title' => 'Sequestrada'])->assertNotFound();
        $this->deleteJson("/api/v1/tenants/{$tenantB->ulid}/galleries/{$gA['id']}")->assertNotFound();
        $this->assertSame('Galeria A', Gallery::withoutGlobalScope('tenant')->first()->title);
    }

    public function test_publico_ve_hoje_por_padrao_escolhe_outra_data_e_o_dia_usa_o_fuso_do_negocio(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC')); // 12h em Brasília
        $hoje = $this->photo($owner, $tenant, '2026-10-26 14:00:00', 'hoje.jpg');
        // 01:30 UTC de 26/10 = 22:30 de 25/10 em Brasília: pertence ao dia 25, não ao 26
        $ontemNoite = $this->photo($owner, $tenant, '2026-10-26 01:30:00', 'ontem-noite.jpg');
        $g = $this->createGallery($owner, $tenant);
        $t = $this->tokenOf($g);

        $this->app['auth']->forgetGuards();
        $info = $this->getJson("/api/v1/galleries/{$t}")->assertOk();
        $info->assertJsonPath('data.today', '2026-10-26')->assertJsonPath('data.allow_download', false);
        $this->assertSame([['date' => '2026-10-26', 'count' => 1], ['date' => '2026-10-25', 'count' => 1]], $info->json('data.days'));
        $this->assertSame('noindex, nofollow', $info->headers->get('X-Robots-Tag'));

        $hojeLista = $this->getJson("/api/v1/galleries/{$t}/photos")->assertOk()->assertJsonPath('date', '2026-10-26');
        $this->assertSame([$hoje->ulid], array_column($hojeLista->json('data'), 'id'));
        $this->assertSame('11:00', $hojeLista->json('data.0.time')); // 14:00 UTC = 11:00 em Brasília

        $outra = $this->getJson("/api/v1/galleries/{$t}/photos?date=2026-10-25")->assertOk();
        $this->assertSame([$ontemNoite->ulid], array_column($outra->json('data'), 'id'));

        $this->getJson("/api/v1/galleries/{$t}/photos?date=2026-10-24")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/galleries/{$t}/photos?date=banana")->assertStatus(422);
        $this->getJson("/api/v1/galleries/{$t}/photos?date=2026-02-31")->assertStatus(422);
    }

    public function test_fotos_de_outros_projetos_pastas_nao_prontas_e_videos_nunca_aparecem(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        [$ownerB, $tenantB] = $this->userWithRole(TenantRole::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC'));
        $minha = $this->photo($owner, $tenant, '2026-10-26 14:00:00');
        $dele = $this->photo($ownerB, $tenantB, '2026-10-26 14:05:00', 'segredo-do-outro.jpg');
        $pendente = $this->photo($owner, $tenant, '2026-10-26 14:10:00');
        $pendente->forceFill(['status' => 'processing'])->save();

        $t = $this->tokenOf($this->createGallery($owner, $tenant));
        $this->app['auth']->forgetGuards();
        $list = $this->getJson("/api/v1/galleries/{$t}/photos")->assertOk();

        $this->assertSame([$minha->ulid], array_column($list->json('data'), 'id'));
        $this->assertStringNotContainsString('segredo-do-outro', $list->getContent());
        $this->getJson("/api/v1/galleries/{$t}/photos/{$dele->ulid}/thumb")->assertNotFound(); // foto de outro projeto
        $this->getJson("/api/v1/galleries/{$t}/photos/{$pendente->ulid}/thumb")->assertNotFound();
    }

    public function test_galeria_de_uma_pasta_mostra_so_as_fotos_dela(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC'));
        $a = $this->photo($owner, $tenant, '2026-10-26 14:00:00', 'a.jpg');
        $this->photo($owner, $tenant, '2026-10-26 14:01:00', 'b.jpg');

        Sanctum::actingAs($owner);
        $col = $this->postJson("/api/v1/tenants/{$tenant->ulid}/collections", ['name' => 'Passeio'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/collections/{$col}/media", ['media' => [$a->ulid]])->assertSuccessful();
        $g = $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Só o passeio', 'collection' => $col])->assertCreated();
        $g->assertJsonPath('data.collection.name', 'Passeio')->assertJsonPath('data.photos_count', 1);

        $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Pasta inexistente', 'collection' => str_repeat('0', 26)])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $list = $this->getJson('/api/v1/galleries/' . $this->tokenOf($g->json('data')) . '/photos')->assertOk();
        $this->assertSame([$a->ulid], array_column($list->json('data'), 'id'));
    }

    public function test_imagens_saem_com_marca_dagua_e_sem_download_por_padrao(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC'));
        $m = $this->photo($owner, $tenant, '2026-10-26 14:00:00');
        $g = $this->createGallery($owner, $tenant);
        $t = $this->tokenOf($g);
        $this->app['auth']->forgetGuards();

        $item = $this->getJson("/api/v1/galleries/{$t}/photos")->json('data.0');
        $this->assertNull($item['download_url']);
        $this->assertStringNotContainsString('storage/app', json_encode($item)); // caminho interno nunca sai

        $thumb = $this->get($item['thumb_url'])->assertOk();
        $this->assertSame('image/webp', $thumb->headers->get('Content-Type'));
        $this->assertSame('noindex, nofollow', $thumb->headers->get('X-Robots-Tag'));
        $this->get($item['view_url'])->assertOk();

        // a versão servida é a MARCADA (g_*), nunca a "thumb"/"preview" limpas
        $marcada = file_get_contents($thumb->baseResponse->getFile()->getPathname());
        $limpa = \Illuminate\Support\Facades\Storage::disk('media')->get($m->variants()->where('kind', 'thumb')->first()->path);
        $this->assertNotSame($limpa, $marcada);
        $this->assertNotNull($m->variants()->where('kind', 'g_thumb')->first());

        // download: bloqueado
        $this->getJson("/api/v1/galleries/{$t}/photos/{$m->ulid}/download")->assertForbidden();
    }

    public function test_download_liberado_entrega_o_original_como_anexo_e_sem_marca_dagua_serve_a_versao_limpa(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 15:00:00', 'UTC'));
        $bytes = $this->jpegBytes(900, 600);
        $id = $this->uploadBytes($owner, $tenant, $bytes, 'original.jpg')->assertCreated()->json('data.id');
        Media::withoutGlobalScope('tenant')->where('ulid', $id)->update(['taken_at' => '2026-10-26 14:00:00']);
        $g = $this->createGallery($owner, $tenant, ['allow_download' => true, 'watermark' => false]);
        $t = $this->tokenOf($g);
        $this->app['auth']->forgetGuards();

        $item = $this->getJson("/api/v1/galleries/{$t}/photos")->json('data.0');
        $this->assertNotNull($item['download_url']);
        $file = $this->get($item['download_url'])->assertOk();
        $this->assertStringContainsString('attachment', $file->headers->get('Content-Disposition'));
        $this->assertSame($bytes, file_get_contents($file->baseResponse->getFile()->getPathname()));

        // sem marca d'água: usa a miniatura normal (não gera g_*)
        $this->get($item['thumb_url'])->assertOk();
        $this->assertNull(Media::withoutGlobalScope('tenant')->where('ulid', $id)->first()->variants()->where('kind', 'g_thumb')->first());
    }

    public function test_link_inativo_trocado_ou_inventado_nao_funciona(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $g = $this->createGallery($owner, $tenant);
        $old = $this->tokenOf($g);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/galleries/{$old}")->assertOk();
        $this->getJson('/api/v1/galleries/' . str_repeat('a', 40))->assertNotFound();
        $this->getJson('/api/v1/galleries/curto')->assertNotFound();

        Sanctum::actingAs($owner);
        $new = $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries/{$g['id']}/rotate-link")->assertOk()->json('data');
        $this->assertNotSame($old, $this->tokenOf($new));

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/galleries/{$old}")->assertNotFound();           // o antigo morreu na hora
        $this->getJson('/api/v1/galleries/' . $this->tokenOf($new))->assertOk();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/tenants/{$tenant->ulid}/galleries/{$g['id']}", ['is_active' => false])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/galleries/' . $this->tokenOf($new))->assertNotFound(); // desativada
    }

    public function test_foto_enviada_depois_de_criar_a_galeria_ganha_a_versao_marcada_sozinha(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->createGallery($owner, $tenant);
        $m = $this->photo($owner, $tenant, now()->toDateTimeString()); // fila "sync" nos testes: processa e prepara a galeria

        $this->assertNotNull($m->variants()->where('kind', 'g_view')->first());
        $this->assertNotNull($m->variants()->where('kind', 'g_thumb')->first());
    }

    public function test_contador_de_visitas_e_validacoes_da_gestao(): void
    {
        [$owner, $tenant] = $this->userWithRole(TenantRole::Owner);
        $g = $this->createGallery($owner, $tenant);
        $t = $this->tokenOf($g);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/galleries/{$t}");
        $this->getJson("/api/v1/galleries/{$t}");
        $this->assertSame(2, Gallery::withoutGlobalScope('tenant')->first()->views_count);

        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => ''])->assertStatus(422);
        $this->postJson("/api/v1/tenants/{$tenant->ulid}/galleries", ['title' => 'Boa', 'allow_download' => 'talvez'])->assertStatus(422);
        $this->deleteJson("/api/v1/tenants/{$tenant->ulid}/galleries/{$g['id']}")->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/galleries/{$t}")->assertNotFound();
    }
}
