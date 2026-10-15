<?php

namespace App\Modules\Tenants\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $role = $this->pivot->role;

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $role instanceof \BackedEnum ? $role->value : $role,
            'joined_at' => $this->pivot->created_at?->toIso8601String(),
        ];
    }
}
