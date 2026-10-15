<?php

namespace App\Models;

use App\Enums\TenantRole;
use App\Models\Concerns\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'asaas_customer_id'])]
#[Hidden(['id', 'password', 'remember_token', 'is_platform_admin', 'asaas_customer_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlid, Notifiable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'invited_by'])
            ->withTimestamps();
    }

    public function roleIn(Tenant $tenant): ?TenantRole
    {
        $role = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $this->id)
            ->value('role');

        // O cast do pivot já devolve o enum; aceita string por segurança.
        return $role instanceof TenantRole ? $role : ($role ? TenantRole::from($role) : null);
    }

    public function sellerProfile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'seller_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }
}
