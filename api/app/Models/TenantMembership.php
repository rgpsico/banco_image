<?php

namespace App\Models;

use App\Enums\TenantRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TenantMembership extends Pivot
{
    protected $table = 'tenant_user';

    public $incrementing = true;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'invited_by'];

    protected function casts(): array
    {
        return ['role' => TenantRole::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
