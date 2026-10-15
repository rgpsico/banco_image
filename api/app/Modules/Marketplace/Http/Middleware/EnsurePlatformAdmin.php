<?php

namespace App\Modules\Marketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Área da equipe da plataforma (moderação). Quem não é admin recebe 404: a área nem aparenta existir. */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isPlatformAdmin()) {
            return response()->json(['message' => 'Recurso não encontrado.'], 404);
        }

        return $next($request);
    }
}
