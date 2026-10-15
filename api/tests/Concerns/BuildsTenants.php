<?php

namespace Tests\Concerns;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;

trait BuildsTenants
{
    /** Cria um projeto e um usuário com o papel pedido nele. @return array{0: User, 1: Tenant} */
    protected function userWithRole(TenantRole $role, ?Tenant $tenant = null): array
    {
        $user = User::factory()->create();
        $tenant ??= Tenant::factory()->create();
        $tenant->users()->attach($user->id, ['role' => $role->value]);

        return [$user, $tenant];
    }
}
