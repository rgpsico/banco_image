<?php

namespace App\Modules\Tenants\Services;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Regras de negócio dos membros de um projeto. */
class MemberService
{
    public function add(Tenant $tenant, User $actor, string $email, TenantRole $role): User
    {
        $this->assertCanAssign($tenant, $actor, $role);

        $user = User::where('email', $email)->first();

        if (! $user) {
            throw ValidationException::withMessages(['email' => ['Nenhum usuário com esse e-mail. Peça para a pessoa criar uma conta primeiro.']]);
        }

        if (TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['email' => ['Essa pessoa já faz parte do projeto.']]);
        }

        $tenant->users()->attach($user->id, ['role' => $role->value, 'invited_by' => $actor->id]);
        Audit::record('tenant.member_added', ['tenant' => $tenant->ulid, 'subject' => $user->ulid, 'role' => $role->value]);

        return $this->find($tenant, $user->ulid);
    }

    public function changeRole(Tenant $tenant, User $actor, string $userUlid, TenantRole $newRole): User
    {
        $target = $this->find($tenant, $userUlid);
        $current = $target->pivot->role;
        $current = $current instanceof TenantRole ? $current : TenantRole::from($current);

        $this->assertCanAssign($tenant, $actor, $newRole);
        $this->assertCanManage($tenant, $actor, $current);

        if ($current === TenantRole::Owner && $newRole !== TenantRole::Owner) {
            $this->assertNotLastOwner($tenant);
        }

        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $target->id)->update(['role' => $newRole->value, 'updated_at' => now()]);
        Audit::record('tenant.member_role_changed', ['tenant' => $tenant->ulid, 'subject' => $target->ulid, 'from' => $current->value, 'to' => $newRole->value]);

        return $this->find($tenant, $userUlid);
    }

    public function remove(Tenant $tenant, User $actor, string $userUlid): void
    {
        $target = $this->find($tenant, $userUlid);
        $current = $target->pivot->role;
        $current = $current instanceof TenantRole ? $current : TenantRole::from($current);

        // Qualquer membro pode sair; para remover outro é preciso ter nível suficiente.
        if ($target->id !== $actor->id) {
            $this->assertCanManage($tenant, $actor, $current);
        }

        if ($current === TenantRole::Owner) {
            $this->assertNotLastOwner($tenant);
        }

        $tenant->users()->detach($target->id);
        Audit::record('tenant.member_removed', ['tenant' => $tenant->ulid, 'subject' => $target->ulid]);
    }

    public function find(Tenant $tenant, string $userUlid): User
    {
        return $tenant->users()->where('users.ulid', $userUlid)->firstOrFail();
    }

    /** Quem pode atribuir um papel: admin atribui até admin; só owner atribui owner. */
    private function assertCanAssign(Tenant $tenant, User $actor, TenantRole $role): void
    {
        $actorRole = $actor->roleIn($tenant);

        if (! $actorRole || ! $actorRole->atLeast(TenantRole::Admin)) {
            throw new AuthorizationException('Você não pode gerenciar membros deste projeto.');
        }

        if ($role === TenantRole::Owner && $actorRole !== TenantRole::Owner) {
            throw new AuthorizationException('Somente o proprietário pode atribuir o papel de proprietário.');
        }
    }

    /** Um admin não mexe em owners; para mexer em alguém é preciso ter nível igual ou superior. */
    private function assertCanManage(Tenant $tenant, User $actor, TenantRole $targetRole): void
    {
        $actorRole = $actor->roleIn($tenant);

        if (! $actorRole || ! $actorRole->atLeast(TenantRole::Admin) || $actorRole->level() < $targetRole->level()) {
            throw new AuthorizationException('Você não pode alterar este membro.');
        }
    }

    private function assertNotLastOwner(Tenant $tenant): void
    {
        $owners = TenantMembership::where('tenant_id', $tenant->id)->where('role', TenantRole::Owner->value)->count();

        if ($owners <= 1) {
            throw ValidationException::withMessages(['role' => ['O projeto precisa ter ao menos um proprietário.']]);
        }
    }
}
