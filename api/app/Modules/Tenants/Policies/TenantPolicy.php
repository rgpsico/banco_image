<?php

namespace App\Modules\Tenants\Policies;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function view(User $user, Tenant $tenant): bool
    {
        return $user->roleIn($tenant) !== null;
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Admin);
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Owner);
    }

    public function viewMembers(User $user, Tenant $tenant): bool
    {
        return $this->view($user, $tenant);
    }

    public function manageMembers(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Admin);
    }

    private function atLeast(User $user, Tenant $tenant, TenantRole $minimum): bool
    {
        return $user->roleIn($tenant)?->atLeast($minimum) ?? false;
    }
}
