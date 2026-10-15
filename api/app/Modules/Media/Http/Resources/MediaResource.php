<?php

namespace App\Modules\Media\Http\Resources;

use App\Modules\Media\Support\MediaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Media */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->relationLoaded('variants') ? $this->variants->keyBy(fn ($v) => $v->kind->value) : collect();

        return [
            'id' => $this->ulid,
            'type' => $this->type->value,
            'name' => $this->display_name,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'status' => $this->status->value,
            'metadata' => $this->metadata ?? new \stdClass(),
            // URLs assinadas e temporárias; o caminho interno do arquivo nunca é exposto.
            'thumbnail_url' => $variants->has('thumb') ? MediaUrls::signed($this->resource, 'thumb') : null,
            'preview_url' => $variants->has('preview') ? MediaUrls::signed($this->resource, 'preview') : null,
            'stream_url' => $this->type->value === 'video' ? MediaUrls::signed($this->resource, 'original') : null,
            'collections' => $this->whenLoaded('collections', fn () => $this->collections->pluck('ulid')->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
