<?php

namespace App\Modules\Marketplace\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\SellerProfile */
class SellerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'display_name' => $this->display_name,
            'bio' => $this->bio,
            'status' => $this->status,
            'member_since' => $this->created_at?->toDateString(),
        ];
    }
}
