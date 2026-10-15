<?php

namespace Tests\Concerns;

use App\Enums\ListingStatus;
use App\Enums\TenantRole;
use App\Models\License;
use App\Models\Listing;
use App\Models\Media;
use App\Models\SellerProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Marketplace\Services\WatermarkService;
use Database\Seeders\LicenseSeeder;

/** Usa MakesMedia + BuildsTenants (declare os três nos testes). */
trait MakesMarketplace
{
    protected function seedLicenses(): void
    {
        $this->seed(LicenseSeeder::class);
    }

    protected function makeSeller(array $profile = [], array $user = []): User
    {
        $u = User::factory()->create($user);
        SellerProfile::factory()->create(array_merge(['user_id' => $u->id], $profile));

        return $u;
    }

    /** Mídia REAL (upload completo, com miniaturas) em um projeto novo do vendedor. */
    protected function realMediaFor(User $seller, ?Tenant $tenant = null): Media
    {
        [$u, $tenant] = $tenant ? [$seller, $tenant] : $this->userWithRole(TenantRole::Owner);
        if (! $tenant->users()->where('users.id', $seller->id)->exists()) {
            $tenant->users()->attach($seller->id, ['role' => 'owner']);
        }
        $res = $this->uploadBytes($seller, $tenant, $this->jpegBytes(900, 600))->assertCreated();

        return Media::withoutGlobalScope('tenant')->where('ulid', $res->json('data.id'))->firstOrFail();
    }

    /** Cria uma publicação com preços e (se publicada) a prévia com marca d'água. */
    protected function makeListing(?User $seller = null, array $attrs = [], array $prices = ['commercial' => 1500]): Listing
    {
        $seller ??= $this->makeSeller();
        $media = $this->realMediaFor($seller);
        $listing = Listing::factory()->create(array_merge([
            'seller_id' => $seller->id, 'tenant_id' => $media->tenant_id, 'media_id' => $media->id,
            'title' => 'Pôr do sol em Ipanema', 'description' => 'Foto do pôr do sol vista do Arpoador.',
            'category' => 'praia', 'tags' => ['praia', 'sol'], 'tags_text' => 'praia sol',
            'status' => ListingStatus::Published, 'rights_declared_at' => now(), 'published_at' => now(),
        ], $attrs));

        foreach ($prices as $code => $cents) {
            $listing->prices()->create(['license_id' => License::where('code', $code)->value('id'), 'price_cents' => $cents, 'currency' => 'BRL']);
        }

        if ($listing->status === ListingStatus::Published) {
            app(WatermarkService::class)->generate($media);
        }

        return $listing->fresh(['prices.license', 'media.variants']);
    }

    protected function validListingPayload(string $mediaUlid, array $over = []): array
    {
        return array_merge([
            'media_id' => $mediaUlid, 'title' => 'Surfista em Arpoador', 'description' => 'Surfista pegando onda ao amanhecer.',
            'category' => 'esporte', 'tags' => ['Surf', ' ONDA ', 'surf'], 'rights_declared' => true,
            'prices' => [['license' => 'personal', 'price_cents' => 990], ['license' => 'commercial', 'price_cents' => 2990]],
        ], $over);
    }
}
