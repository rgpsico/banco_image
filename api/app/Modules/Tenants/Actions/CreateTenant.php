<?php

namespace App\Modules\Tenants\Actions;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenant
{
    public function handle(User $creator, string $name, ?string $slug = null): Tenant
    {
        return DB::transaction(function () use ($creator, $name, $slug) {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug ?: $this->uniqueSlug($name),
                'created_by' => $creator->id,
            ]);

            // O criador é sempre o owner; o papel vem do servidor, nunca do cliente.
            $tenant->users()->attach($creator->id, ['role' => TenantRole::Owner->value]);

            Audit::record('tenant.created', ['tenant' => $tenant->ulid]);

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'projeto';
        $slug = $base;
        $i = 2;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
