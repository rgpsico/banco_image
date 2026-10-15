<?php

namespace App\Modules\Tenants\Http\Controllers;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Tenants\Http\Requests\AddMemberRequest;
use App\Modules\Tenants\Http\Requests\UpdateMemberRequest;
use App\Modules\Tenants\Http\Resources\MemberResource;
use App\Modules\Tenants\Services\MemberService;
use Illuminate\Http\JsonResponse;

class TenantMemberController extends Controller
{
    public function __construct(private MemberService $members)
    {
    }

    public function index(Tenant $tenant)
    {
        $this->authorize('viewMembers', $tenant);

        return MemberResource::collection($tenant->users()->orderBy('users.name')->get());
    }

    public function store(AddMemberRequest $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('manageMembers', $tenant);

        $member = $this->members->add($tenant, $request->user(), $request->email, TenantRole::from($request->role));

        return (new MemberResource($member))->response()->setStatusCode(201);
    }

    public function update(UpdateMemberRequest $request, Tenant $tenant, string $userId): MemberResource
    {
        $this->authorize('manageMembers', $tenant);

        return new MemberResource(
            $this->members->changeRole($tenant, $request->user(), $userId, TenantRole::from($request->role))
        );
    }

    public function destroy(\Illuminate\Http\Request $request, Tenant $tenant, string $userId): JsonResponse
    {
        $this->authorize('view', $tenant); // qualquer membro pode sair; remover outro é validado no service

        $this->members->remove($tenant, $request->user(), $userId);

        return response()->json(null, 204);
    }
}
