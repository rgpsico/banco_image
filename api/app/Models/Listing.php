<?php

namespace App\Models;

use App\Enums\ListingStatus;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'seller_id', 'tenant_id', 'media_id', 'title', 'description', 'category', 'tags', 'status', 'rejection_reason',
    'reviewed_by', 'reviewed_at', 'published_at', 'rights_declared_at', 'has_people', 'model_release_confirmed',
])]
#[Hidden(['id', 'seller_id', 'tenant_id', 'media_id', 'reviewed_by'])]
class Listing extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ListingStatus::class,
            'tags' => 'array',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'rights_declared_at' => 'datetime',
            'has_people' => 'boolean',
            'model_release_confirmed' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function media(): BelongsTo
    {
        // A mídia pertence a um tenant privado; a publicação cruza tenants por desenho.
        return $this->belongsTo(Media::class)->withoutGlobalScope('tenant');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ListingLicense::class);
    }

    public function licenses(): BelongsToMany
    {
        return $this->belongsToMany(License::class, 'listing_licenses')->withPivot(['price_cents', 'currency'])->withTimestamps();
    }
}
