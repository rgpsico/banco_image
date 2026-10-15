<?php

namespace App\Models;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id', 'uploaded_by', 'type', 'original_name', 'display_name', 'disk', 'path', 'mime_type',
    'extension', 'size_bytes', 'checksum_sha256', 'width', 'height', 'duration_seconds', 'metadata', 'status',
])]
// Caminho interno, disco e ids numéricos nunca saem pela API.
#[Hidden(['id', 'tenant_id', 'uploaded_by', 'disk', 'path', 'checksum_sha256'])]
class Media extends Model
{
    use BelongsToTenant, HasFactory, HasUlid, SoftDeletes;

    protected $table = 'media';

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'status' => MediaStatus::class,
            'metadata' => 'array',
            'size_bytes' => 'integer',
        ];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class, 'collection_media')
            ->withoutGlobalScope('tenant')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function listing(): HasOne
    {
        return $this->hasOne(Listing::class);
    }
}
