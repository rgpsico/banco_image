<?php

namespace App\Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Modules\Tenants\Actions\CreateTenant;
use App\Modules\Tenants\Http\Requests\StoreTenantRequest;
use App\Modules\Tenants\Http\Requests\UpdateTenantRequest;
use App\Modules\Tenants\Http\Resources\TenantResource;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /** Somente os projetos dos quais o usuário faz parte. */
    public function index(Request $request)
    {
        return TenantResource::collection($request->user()->tenants()->orderBy('tenants.name')->get());
    }

    public function store(StoreTenantRequest $request, CreateTenant $action): JsonResponse
    {
        $tenant = $action->handle($request->user(), $request->name, $request->slug);
        $tenant = $request->user()->tenants()->where('tenants.id', $tenant->id)->first();

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        $this->authorize('view', $tenant);

        return new TenantResource($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        $this->authorize('update', $tenant);

        $tenant->update($request->validated());
        Audit::record('tenant.updated', ['tenant' => $tenant->ulid]);

        return new TenantResource($tenant->fresh());
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $this->authorize('delete', $tenant);

        $tenant->delete();
        Audit::record('tenant.deleted', ['tenant' => $tenant->ulid]);

        return response()->json(null, 204);
    }
}
