<?php

namespace Tests\Feature\Media;

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class ImportUnsplashTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    private string $manifest = 'storage/framework/testing-bank-manifest.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        // Arquivo de controle PRÓPRIO do teste: nunca o de produção (storage/app/unsplash-imported.json).
        config(['services.unsplash.key' => 'test-key', 'mediahub.unsplash_state' => storage_path('framework/testing-unsplash-state.json')]);
        File::put(base_path($this->manifest), json_encode([['query' => 'burger', 'title' => 'Hambúrguer', 'collection' => 'Comidas', 'count' => 2, 'tags' => ['Burger', 'lanche']]]));
        @unlink(storage_path('framework/testing-unsplash-state.json'));
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->manifest));
        @unlink(storage_path('framework/testing-unsplash-state.json'));
        parent::tearDown();
    }

    private function fakeUnsplash(): void
    {
        $photo = fn (string $id) => [
            'id' => $id, 'user' => ['name' => 'Fulano'], 'links' => ['html' => "https://unsplash.com/photos/$id", 'download_location' => "https://api.unsplash.com/photos/$id/download"],
            'urls' => ['regular' => "https://images.unsplash.com/$id"],
        ];

        Http::fake([
            'api.unsplash.com/search/photos*' => Http::response(['results' => [$photo('aaa'), $photo('bbb'), $photo('ccc')]]),
            'api.unsplash.com/photos/*/download' => Http::response(['url' => 'x']),
            'images.unsplash.com/*' => Http::response($this->jpegBytes(900, 600), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_imports_photos_into_collection_with_title_and_tracks_download(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->fakeUnsplash();

        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();

        $names = Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderBy('id')->pluck('display_name')->all();
        $this->assertSame(['Hambúrguer 1', 'Hambúrguer 2'], $names);
        $this->assertSame(2, Collection::withoutGlobalScope('tenant')->where('name', 'Comidas')->firstOrFail()->media()->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), '/photos/aaa/download') && $r->hasHeader('Authorization', 'Client-ID test-key'));
    }

    public function test_second_run_does_not_duplicate(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->fakeUnsplash();

        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();

        $this->assertSame(2, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_requires_key_and_valid_tenant(): void
    {
        config(['services.unsplash.key' => '']);
        $this->artisan('mediahub:import-unsplash', ['tenant' => 'x'])->assertFailed();

        config(['services.unsplash.key' => 'k']);
        $this->artisan('mediahub:import-unsplash', ['tenant' => 'inexistente', '--manifest' => $this->manifest])->assertFailed();
    }

    public function test_stops_cleanly_when_rate_limited(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        Http::fake(['api.unsplash.com/*' => Http::response('Rate Limit Exceeded', 403)]);

        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_manifest_supports_subfolders_tags_and_refresh_reorganizes_existing_photos(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->fakeUnsplash();
        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();

        $first = Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderBy('id')->first();
        $this->assertSame(['burger', 'lanche'], $first->tagList());

        // O manifesto muda: agora a categoria vai para Comidas/Lanches e ganha mais uma tag.
        File::put(base_path($this->manifest), json_encode([['query' => 'burger', 'title' => 'Hambúrguer', 'collection' => 'Comidas/Lanches', 'count' => 2, 'tags' => ['burger', 'xis']]]));
        $this->artisan('mediahub:import-unsplash', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest, '--refresh' => true])->assertSuccessful();

        $lanches = Collection::withoutGlobalScope('tenant')->where('name', 'Lanches')->firstOrFail();
        $this->assertSame('Comidas', $lanches->parent->name);
        $this->assertSame(2, $lanches->media()->count());
        $this->assertSame(['burger', 'xis'], $first->fresh()->tagList());
        $this->assertSame(1, Collection::withoutGlobalScope('tenant')->where('name', 'Comidas')->count()); // reaproveitou a pasta, sem duplicar
        $this->assertSame(0, Collection::withoutGlobalScope('tenant')->where('name', 'Comidas')->firstOrFail()->media()->count()); // saiu da pasta antiga
    }
}
