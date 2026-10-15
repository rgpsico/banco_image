<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Visão do COMPRADOR: sem comissão nem ganhos do vendedor. @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'total_cents' => $this->total_cents,
            'currency' => $this->currency,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->ulid,
                'title' => $i->title_snapshot,
                'license' => $i->license?->code,
                'license_name' => $i->license?->name,
                'price_cents' => $i->unit_price_cents,
                'listing_id' => $i->listing?->ulid,
                'thumbnail_url' => $i->listing ? "/api/v1/market/listings/{$i->listing->ulid}/image/thumb" : null,
            ])->values()),
            'payment' => $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('id')->take(1)->map(fn ($p) => [
                'id' => $p->ulid, 'status' => $p->status->value, 'gateway' => $p->gateway,
            ])->first()),
        ];
    }
}
