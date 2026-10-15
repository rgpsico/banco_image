<?php

namespace App\Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Visão PÚBLICA. Só traz o que o comprador precisa: nada de ids internos, projeto, caminhos ou arquivo original.
 * As imagens são as prévias com marca d'água.
 *
 * @mixin \App\Models\Listing
 */
class PublicListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $categories = config('mediahub.marketplace.categories');
        $prices = $this->prices->map(fn ($p) => [
            'license' => $p->license->code, 'name' => $p->license->name, 'description' => $p->license->description,
            'allows_commercial' => $p->license->allows_commercial, 'price_cents' => $p->price_cents,
        ])->sortBy('price_cents')->values();

        return [
            'id' => $this->ulid,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->media->type->value,
            'category' => $this->category,
            'category_label' => $categories[$this->category] ?? null,
            'tags' => $this->tags ?? [],
            'width' => $this->media->width,
            'height' => $this->media->height,
            'duration_seconds' => $this->media->duration_seconds,
            'size_bytes' => $this->media->size_bytes,
            'format' => strtoupper($this->media->extension),
            'seller' => ['slug' => $this->seller->sellerProfile->slug, 'name' => $this->seller->sellerProfile->display_name],
            'prices' => $prices,
            'min_price_cents' => $prices->min('price_cents'),
            'sales_count' => $this->sales_count,
            'thumbnail_url' => "/api/v1/market/listings/{$this->ulid}/image/thumb",
            'image_url' => "/api/v1/market/listings/{$this->ulid}/image/large",
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
