<?php

namespace App\Modules\Marketplace\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->user()?->sellerProfile;

        if (! $profile) {
            return response()->json(['message' => 'Torne-se vendedor para acessar esta área.'], 403);
        }

        if (! $profile->isActive()) {
            return response()->json(['message' => 'Sua conta de vendedor está suspensa.'], 403);
        }

        return $next($request);
    }
}
