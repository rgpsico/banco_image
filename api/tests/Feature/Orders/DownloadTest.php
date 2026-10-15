<?php

namespace Tests\Feature\Orders;

use App\Models\DownloadGrant;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\MakesMarketplace;
use Tests\Concerns\MakesMedia;
use Tests\Concerns\MakesOrders;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use BuildsTenants, MakesMarketplace, MakesMedia, MakesOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeDisks();
        $this->seedLicenses();
    }

    public function test_download_is_blocked_until_the_payment_is_confirmed(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->makeListing();
        $this->placeOrder($buyer, [[$listing, 'commercial']])->assertCreated();
        $item = OrderItem::first();

        // pedido pendente: sem "grant" e fora de "Minhas compras"
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertStatus(403)->assertJsonValidationErrors('download');
        $this->getJson('/api/v1/purchases')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, DownloadGrant::count());

        // tentar o caminho do arquivo sem assinatura / com o ulid de um grant inexistente
        $this->get('/api/v1/files/purchase/01h0000000000000000000000/download')->assertForbidden();
    }

    public function test_after_payment_the_buyer_downloads_the_exact_original_with_attachment(): void
    {
        $buyer = User::factory()->create();
        $listing = $this->makeListing(null, ['title' => 'Pôr do sol em Ipanema'], ['commercial' => 1500]);
        $original = Storage::disk('media')->get($listing->media->path);
        $this->paidOrder($buyer, [[$listing, 'commercial']]);
        $item = OrderItem::first();

        $res = $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertOk()->assertJsonPath('data.downloads_remaining', 9);
        $url = $res->json('data.url');
        $this->assertStringStartsWith('/api/v1/files/purchase/', $url);

        $file = $this->get($url)->assertOk();
        $this->assertStringContainsString('attachment', $file->headers->get('Content-Disposition'));
        $this->assertStringContainsString('commercial.jpg', $file->headers->get('Content-Disposition'));
        $this->assertSame($original, file_get_contents($file->baseResponse->getFile()->getPathname())); // original, sem marca d'água

        $this->getJson('/api/v1/purchases')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.downloads_remaining', 9)->assertJsonPath('data.0.license', 'commercial');
    }

    public function test_download_limit_expiry_and_revocation_are_enforced(): void
    {
        $buyer = User::factory()->create();
        $this->paidOrder($buyer, [[$this->makeListing(), 'commercial']]);
        $item = OrderItem::first();
        $grant = DownloadGrant::first();
        $grant->update(['max_downloads' => 2]);

        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertOk()->assertJsonPath('data.downloads_remaining', 1);
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertOk()->assertJsonPath('data.downloads_remaining', 0);
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertStatus(403); // limite atingido

        $grant->update(['max_downloads' => 9, 'expires_at' => now()->subMinute()]);
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertStatus(403); // prazo vencido

        $grant->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertStatus(403); // revogado
        $this->getJson('/api/v1/purchases')->assertJsonPath('data.0.downloads_remaining', 0);
    }

    public function test_only_the_buyer_can_download_and_signed_links_are_short_lived_and_tamper_proof(): void
    {
        $buyer = User::factory()->create();
        $this->paidOrder($buyer, [[$this->makeListing(), 'commercial']]);
        $item = OrderItem::first();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->assertNotFound(); // item de outra pessoa

        Sanctum::actingAs($buyer);
        $url = $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->json('data.url');
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=00', $url))->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();

        $this->travel(3)->minutes();
        $this->get($url)->assertForbidden(); // link vence em 2 minutos
    }

    public function test_a_refund_between_issuing_and_using_the_link_blocks_the_file(): void
    {
        $buyer = User::factory()->create();
        $this->paidOrder($buyer, [[$this->makeListing(), 'commercial']]);
        $item = OrderItem::first();
        $url = $this->postJson("/api/v1/purchases/{$item->ulid}/download-url")->json('data.url');

        $this->webhook(['event_id' => 'evt_r', 'type' => 'payment.refunded', 'reference' => Payment::first()->gateway_reference])->assertOk();

        $this->get($url)->assertForbidden(); // revalidado na entrega
    }

    public function test_marketplace_public_endpoints_never_leak_the_original_even_to_buyers(): void
    {
        $listing = $this->makeListing();
        $this->paidOrder(User::factory()->create(), [[$listing, 'commercial']]);

        // a única rota pública de imagem entrega a prévia com marca d'água
        $this->get("/api/v1/market/listings/{$listing->ulid}/image/large")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->get("/api/v1/market/listings/{$listing->ulid}/image/original")->assertNotFound();
    }

    public function test_deleting_the_listing_media_is_blocked_after_a_sale_so_buyers_keep_access(): void
    {
        $listing = $this->makeListing();
        $this->paidOrder(User::factory()->create(), [[$listing, 'commercial']]);

        // o vendedor despublica; a mídia continua guardada (comprador mantém o direito de baixar)
        Sanctum::actingAs($listing->seller);
        $this->postJson("/api/v1/seller/listings/{$listing->ulid}/unpublish")->assertOk();
        $this->deleteJson("/api/v1/seller/listings/{$listing->ulid}")->assertStatus(422); // já teve venda
        Storage::disk('media')->assertExists($listing->media->path);
    }
}
