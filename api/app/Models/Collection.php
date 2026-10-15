<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'parent_id', 'name', 'slug', 'description', 'created_by'])]
#[Hidden(['id', 'tenant_id', 'parent_id', 'created_by'])]
class Collection extends Model
{
    use BelongsToTenant, HasFactory, HasUlid, SoftDeletes;

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'collection_media')
            ->withoutGlobalScope('tenant')
            ->withPivot('position')
            ->withTimestamps();
    }
}
