<?php

namespace App\Modules\Media\Policies;

use App\Enums\TenantRole;
use App\Models\Media;
use App\Models\Tenant;
use App\Models\User;

class MediaPolicy
{
    public function viewAny(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Viewer);
    }

    public function create(User $user, Tenant $tenant): bool
    {
        return $this->atLeast($user, $tenant, TenantRole::Editor);
    }

    public function view(User $user, Media $media): bool
    {
        return $this->atLeast($user, $media->tenant, TenantRole::Viewer);
    }

    public function download(User $user, Media $media): bool
    {
        return $this->atLeast($user, $media->tenant, TenantRole::Viewer);
    }

    public function update(User $user, Media $media): bool
    {
        return $this->atLeast($user, $media->tenant, TenantRole::Editor);
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->atLeast($user, $media->tenant, TenantRole::Editor);
    }

    private function atLeast(User $user, Tenant $tenant, TenantRole $minimum): bool
    {
        return $user->roleIn($tenant)?->atLeast($minimum) ?? false;
    }
}
