<?php

namespace Tests\Feature\Marketplace;

use App\Enums\ListingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_catalog_is_public_and_lists_only_published_listings_of_active_sellers(): void
    {
        $seller = $this->makeSeller();
        $this->makeListing($seller, ['title' => 'Publicada']);
        foreach ([ListingStatus::Draft, ListingStatus::PendingReview, ListingStatus::Rejected, ListingStatus::Suspended, ListingStatus::Archived] as $status) {
            $this->makeListing($seller, ['title' => 'Oculta ' . $status->value, 'status' => $status]);
        }
        $this->makeListing($this->makeSeller(['status' => 'suspended']), ['title' => 'De vendedor suspenso']);

        $res = $this->getJson('/api/v1/market/listings')->assertOk(); // sem token
        $res->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Publicada');
    }

    public function test_drafts_and_other_hidden_listings_return_404_on_detail_and_image(): void
    {
        $draft = $this->makeListing(null, ['status' => ListingStatus::Draft]);
        $pending = $this->makeListing(null, ['status' => ListingStatus::PendingReview]);

        foreach ([$draft, $pending] as $l) {
            $this->getJson("/api/v1/market/listings/{$l->ulid}")->assertNotFound();
            $this->get("/api/v1/market/listings/{$l->ulid}/image/thumb")->assertNotFound();
            $this->get("/api/v1/market/listings/{$l->ulid}/image/large")->assertNotFound();
        }
    }

    public function test_detail_exposes_only_public_data(): void
    {
        $listing = $this->makeListing(null, [], ['personal' => 990, 'commercial' => 2990, 'extended' => 9990]);

        $res = $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertOk();
        $data = $res->json('data');

        $this->assertSame(990, $data['min_price_cents']);
        $this->assertCount(3, $data['prices']);
        $this->assertSame(['personal', 'commercial', 'extended'], array_column($data['prices'], 'license'));
        $this->assertStringStartsWith('/api/v1/market/listings/', $data['thumbnail_url']);

        // nada de interno
        foreach (['media', 'media_id', 'tenant_id', 'seller_id', 'path', 'disk', 'status', 'rejection_reason', 'reviewed_by'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertStringNotContainsString('tenants/', $res->getContent()); // nenhum caminho de arquivo
        $this->assertStringNotContainsString('.jpg', $res->getContent());
        $this->assertSame(['slug', 'name'], array_keys($data['seller']));
    }

    public function test_public_images_are_watermarked_variants_never_the_original_or_clean_preview(): void
    {
        $listing = $this->makeListing();
        $media = $listing->media;
        $wm = $media->variants->firstWhere('kind.value', 'watermarked');
        $preview = $media->variants->firstWhere('kind.value', 'preview');

        $res = $this->get("/api/v1/market/listings/{$listing->ulid}/image/large")->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('public', $res->headers->get('Cache-Control'));
        $this->assertSame(Storage::disk('media')->get($wm->path), $res->streamedContent() ?: file_get_contents($res->baseResponse->getFile()->getPathname()));
        $this->assertNotSame(Storage::disk('media')->get($preview->path), file_get_contents($res->baseResponse->getFile()->getPathname()));

        // não existe caminho público para original/preview limpa
        $this->get("/api/v1/market/listings/{$listing->ulid}/image/original")->assertNotFound();
        $this->get("/api/v1/market/listings/{$listing->ulid}/image/preview")->assertNotFound();
    }

    public function test_search_filters_sort_and_pagination(): void
    {
        $a = $this->makeSeller(['slug' => 'ana-foto']);
        $b = $this->makeSeller(['slug' => 'beto-foto']);
        $this->makeListing($a, ['title' => 'Praia de Ipanema', 'category' => 'praia', 'tags' => ['mar', 'verao'], 'tags_text' => 'mar verao', 'published_at' => now()->subDays(3), 'sales_count' => 1], ['commercial' => 5000]);
        $this->makeListing($a, ['title' => 'Trilha na Pedra', 'category' => 'natureza', 'tags' => ['trilha'], 'tags_text' => 'trilha', 'published_at' => now()->subDays(2), 'sales_count' => 9], ['personal' => 1000]);
        $this->makeListing($b, ['title' => 'Prato típico', 'description' => 'Moqueca capixaba', 'category' => 'gastronomia', 'tags' => ['comida'], 'tags_text' => 'comida', 'published_at' => now()->subDay()], ['commercial' => 3000, 'extended' => 9000]);

        $titles = fn (string $qs) => collect($this->getJson("/api/v1/market/listings?$qs")->assertOk()->json('data'))->pluck('title')->all();

        $this->assertSame(['Prato típico', 'Trilha na Pedra', 'Praia de Ipanema'], $titles(''));                  // mais recentes
        $this->assertSame(['Trilha na Pedra', 'Praia de Ipanema', 'Prato típico'], $titles('sort=popular'));
        $this->assertSame(['Trilha na Pedra', 'Prato típico', 'Praia de Ipanema'], $titles('sort=price_asc'));
        $this->assertSame(['Praia de Ipanema', 'Prato típico', 'Trilha na Pedra'], $titles('sort=price_desc'));
        $this->assertSame(['Praia de Ipanema'], $titles('q=ipanema'));
        $this->assertSame(['Prato típico'], $titles('q=moqueca'));                                                  // busca na descrição
        $this->assertSame(['Praia de Ipanema'], $titles('q=verao'));                                                // e nas tags
        $this->assertSame(['Trilha na Pedra'], $titles('category=natureza'));
        $this->assertSame(['Prato típico'], $titles('tag=comida'));
        $this->assertEqualsCanonicalizing(['Praia de Ipanema', 'Trilha na Pedra'], $titles('seller=ana-foto'));
        $this->assertSame(['Praia de Ipanema', 'Prato típico'], $titles('min_price=2500&max_price=6000&sort=price_desc'));
        $this->assertSame([], $titles('q=' . urlencode('%')));                                                      // curinga tratado como texto
        $this->getJson('/api/v1/market/listings?category=inexistente')->assertStatus(422);
        $this->getJson('/api/v1/market/listings?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
    }

    public function test_type_filter_and_categories_with_counts(): void
    {
        $this->makeListing(null, ['category' => 'praia']);
        $this->makeListing(null, ['category' => 'praia']);
        $this->makeListing(null, ['category' => 'cidade']);

        $this->getJson('/api/v1/market/listings?type=video')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/market/listings?type=image')->assertOk()->assertJsonCount(3, 'data');

        $cats = collect($this->getJson('/api/v1/market/categories')->assertOk()->json('data'))->pluck('count', 'id');
        $this->assertSame(2, $cats['praia']);
        $this->assertSame(1, $cats['cidade']);
        $this->assertSame(0, $cats['eventos']);
    }

    public function test_seller_profile_is_public_and_hides_suspended_sellers(): void
    {
        $seller = $this->makeSeller(['slug' => 'ana-foto', 'display_name' => 'Ana Foto', 'bio' => 'Fotógrafa']);
        $this->makeListing($seller);
        $this->makeListing($seller, ['status' => ListingStatus::Draft]);

        $this->getJson('/api/v1/market/sellers/ana-foto')->assertOk()
            ->assertJsonPath('data.display_name', 'Ana Foto')->assertJsonPath('data.listings_count', 1)
            ->assertJsonMissingPath('data.user_id')->assertJsonMissingPath('data.email');

        $this->makeSeller(['slug' => 'banida', 'status' => 'suspended']);
        $this->getJson('/api/v1/market/sellers/banida')->assertNotFound();
        $this->getJson('/api/v1/market/sellers/nao-existe')->assertNotFound();
    }

    public function test_views_counter_increments(): void
    {
        $listing = $this->makeListing();

        $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertOk();
        $this->getJson("/api/v1/market/listings/{$listing->ulid}")->assertOk();

        $this->assertSame(2, $listing->fresh()->views_count);
    }
}
