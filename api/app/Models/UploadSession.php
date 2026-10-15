<?php

namespace App\Models;

use App\Enums\UploadSessionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'user_id', 'status', 'original_name', 'mime_type', 'size_bytes', 'chunk_size', 'total_chunks',
    'received_chunks', 'temp_path', 'expected_checksum', 'media_id', 'error', 'expires_at', 'completed_at',
])]
#[Hidden(['id', 'tenant_id', 'user_id', 'temp_path', 'media_id'])]
class UploadSession extends Model
{
    use BelongsToTenant, HasFactory, HasUlid;

    protected function casts(): array
    {
        return [
            'status' => UploadSessionStatus::class,
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class)->withoutGlobalScope('tenant');
    }
}
