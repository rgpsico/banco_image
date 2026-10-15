<?php

namespace App\Modules\Collections\Policies;

use App\Enums\TenantRole;
use App\Models\Collection;
use App\Models\Tenant;
use App\Models\User;

class CollectionPolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Viewer);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Editor);
    }

    public function update(User $user, Collection $collection): bool
    {
        return $this->atLeast($user, $collection->tenant, TenantRole::Editor);
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $this->atLeast($user, $collection->tenant, TenantRole::Editor);
    }

    private function atLeast(User $user, Tenant $tenant, TenantRole $minimum): bool
    {
        return $user->roleIn($tenant)?->atLeast($minimum) ?? false;
    }
}
