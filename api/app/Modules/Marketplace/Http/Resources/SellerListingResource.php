<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Modules\Media\Http\Resources\MediaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Visão do DONO (e da moderação): inclui status e a prévia sem marca d'água por URL assinada. */
/** @mixin \App\Models\Listing */
class SellerListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'has_people' => $this->has_people,
            'model_release_confirmed' => $this->model_release_confirmed,
            'rights_declared_at' => $this->rights_declared_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'prices' => $this->whenLoaded('prices', fn () => $this->prices->map(fn ($p) => [
                'license' => $p->license?->code, 'name' => $p->license?->name, 'price_cents' => $p->price_cents,
            ])->values()),
            'media' => $this->whenLoaded('media', fn () => new MediaResource($this->media)),
            'seller' => $this->whenLoaded('seller', fn () => ['name' => $this->seller->sellerProfile?->display_name ?? $this->seller->name, 'slug' => $this->seller->sellerProfile?->slug]),
            'sales_count' => $this->sales_count,
            'views_count' => $this->views_count,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
