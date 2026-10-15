<?php

namespace App\Modules\Media\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\UploadSession */
class UploadSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'size_bytes' => $this->size_bytes,
            'chunk_size' => $this->chunk_size,
            'total_chunks' => $this->total_chunks,
            'received_chunks' => $this->received_chunks,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
