<?php

namespace App\Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantApiKey;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Gestão das chaves de API do projeto (administradores). A chave completa aparece uma única vez, na criação. */
class TenantApiKeyController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        return response()->json(['data' => TenantApiKey::where('tenant_id', $tenant->id)->with('creator')->latest('id')->get()->map(fn (TenantApiKey $k) => $this->present($k))->values()]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $this->authorize('update', $tenant);

        $v = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $active = TenantApiKey::where('tenant_id', $tenant->id)->whereNull('revoked_at')->count();
        if ($active >= TenantApiKey::MAX_ACTIVE_PER_TENANT) {
            throw ValidationException::withMessages(['name' => ['Limite de ' . TenantApiKey::MAX_ACTIVE_PER_TENANT . ' chaves ativas. Revogue alguma antes de criar outra.']]);
        }

        [$key, $plain] = TenantApiKey::issue($tenant, trim(strip_tags($v['name'])), $request->user(), isset($v['expires_in_days']) ? now()->addDays((int) $v['expires_in_days']) : null);
        Audit::record('apikey.created', ['key' => $key->ulid, 'tenant_key_prefix' => $key->prefix]);

        return response()->json(['data' => $this->present($key->load('creator')) + ['key' => $plain]], 201, ['Cache-Control' => 'no-store']);
    }

    public function destroy(Tenant $tenant, string $key): JsonResponse
    {
        $this->authorize('update', $tenant);

        $model = TenantApiKey::where('tenant_id', $tenant->id)->where('ulid', $key)->firstOrFail();

        if (! $model->revoked_at) {
            $model->forceFill(['revoked_at' => now()])->save();
            Audit::record('apikey.revoked', ['key' => $model->ulid]);
        }

        return response()->json(['data' => $this->present($model->load('creator'))]);
    }

    private function present(TenantApiKey $k): array
    {
        return [
            'id' => $k->ulid, 'name' => $k->name, 'prefix' => $k->prefix,
            'status' => $k->revoked_at ? 'revoked' : ($k->expires_at?->isPast() ? 'expired' : 'active'),
            'created_by' => $k->creator?->name, 'created_at' => $k->created_at?->toIso8601String(),
            'last_used_at' => $k->last_used_at?->toIso8601String(), 'expires_at' => $k->expires_at?->toIso8601String(),
            'revoked_at' => $k->revoked_at?->toIso8601String(),
        ];
    }
}
