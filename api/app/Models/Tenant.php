<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'storage_quota_bytes', 'settings', 'created_by'])]
#[Hidden(['id'])]
class Tenant extends Model
{
    use HasFactory, HasUlid, SoftDeletes;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'storage_quota_bytes' => 'integer',
            'storage_used_bytes' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'invited_by'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class)->withoutGlobalScope('tenant');
    }

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class)->withoutGlobalScope('tenant');
    }

    public function availableStorageBytes(): int
    {
        return max(0, $this->storage_quota_bytes - $this->storage_used_bytes);
    }
}
