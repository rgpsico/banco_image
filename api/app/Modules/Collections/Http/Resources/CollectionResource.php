<?php

namespace App\Modules\Collections\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Collection */
class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'parent_id' => $this->parent?->ulid,
            'media_count' => $this->media_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
