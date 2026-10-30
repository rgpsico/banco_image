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

class GenerateBankImagesTest extends TestCase
{
    use BuildsTenants, MakesMedia, RefreshDatabase;

    private string $manifest = 'storage/framework/testing-generate-manifest.json';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        config(['services.gemini.key' => 'k']);
        File::put(base_path($this->manifest), json_encode([['title' => 'Caipirinha de limão', 'subject' => 'caipirinha', 'collection' => 'Bebidas/Praia', 'tags' => ['Caipirinha', 'praia']]]));
    }

    protected function tearDown(): void
    {
        @unlink(base_path($this->manifest));
        parent::tearDown();
    }

    private function gemini(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode($this->pngBytes(300, 300))]]]]]]])]);
    }

    public function test_generates_image_into_subfolder_with_tags_and_sends_key_in_header_not_url(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->gemini();

        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();

        $media = Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('Caipirinha de limão', $media->display_name);
        $this->assertSame(['caipirinha', 'praia'], $media->tagList());
        $this->assertSame('Praia', Collection::withoutGlobalScope('tenant')->where('name', 'Praia')->firstOrFail()->name);
        $this->assertSame('Bebidas', Collection::withoutGlobalScope('tenant')->where('name', 'Praia')->first()->parent->name);
        Http::assertSent(fn ($r) => $r->hasHeader('x-goog-api-key', 'k') && ! str_contains($r->url(), 'key=') && str_contains($r['contents'][0]['parts'][0]['text'], 'caipirinha'));
    }

    public function test_second_run_skips_existing(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        $this->gemini();
        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->assertSame(1, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
        Http::assertSentCount(1); // a segunda rodada não chamou o Gemini de novo
    }

    public function test_quota_error_stops_cleanly_without_saving(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);

        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }

    public function test_requires_key_and_handles_response_without_image(): void
    {
        [, $tenant] = $this->userWithRole(TenantRole::Owner);
        config(['services.gemini.key' => '']);
        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertFailed();

        config(['services.gemini.key' => 'k']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'não posso']]]]]])]);
        $this->artisan('mediahub:generate-images', ['tenant' => $tenant->ulid, '--manifest' => $this->manifest])->assertSuccessful();
        $this->assertSame(0, Media::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count());
    }
}
