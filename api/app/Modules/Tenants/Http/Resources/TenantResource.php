<?php

namespace App\Modules\Tenants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->pivot?->role ?? null;

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $role instanceof \BackedEnum ? $role->value : $role,
            'storage' => [
                'quota_bytes' => $this->storage_quota_bytes,
                'used_bytes' => $this->storage_used_bytes,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
