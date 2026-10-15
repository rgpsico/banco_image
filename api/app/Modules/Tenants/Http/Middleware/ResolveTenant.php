<?php

namespace App\Modules\Tenants\Http\Middleware;

use App\Modules\Tenants\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant atual para rotas de recursos do projeto (mídia, coleções...).
 * O X-Tenant só SELECIONA entre os projetos do usuário autenticado: se ele não for membro, é 404.
 * Nunca se confia no tenant_id enviado pelo front-end.
 */
class ResolveTenant
{
    public function __construct(private CurrentTenant $current)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $ulid = $request->route('tenant')?->ulid ?? $request->header('X-Tenant');
        $user = $request->user();

        if (! $user || ! is_string($ulid) || $ulid === '') {
            return response()->json(['message' => 'Projeto não informado.'], 400);
        }

        $tenant = $user->tenants()->where('tenants.ulid', $ulid)->first();

        if (! $tenant) {
            return response()->json(['message' => 'Recurso não encontrado.'], 404);
        }

        $this->current->set($tenant);
        $request->attributes->set('tenant_role', $tenant->pivot->role);

        try {
            return $next($request);
        } finally {
            $this->current->clear();
        }
    }
}
