<?php

namespace App\Models;

use App\Enums\VariantKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['media_id', 'kind', 'disk', 'path', 'width', 'height', 'size_bytes'])]
#[Hidden(['id', 'media_id', 'disk', 'path'])]
class MediaVariant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['kind' => VariantKind::class];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class)->withoutGlobalScope('tenant');
    }
}
