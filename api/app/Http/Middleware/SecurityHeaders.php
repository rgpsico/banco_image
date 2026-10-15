<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cabeçalhos de segurança em toda resposta da API e "no-store" nas respostas privadas (JSON autenticado). */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->remove('X-Powered-By');

        // Dados de quem está logado nunca devem ficar em cache de navegador/proxy compartilhado.
        // Imagens e arquivos já definem a própria política (Cache-Control), então não são tocados.
        if ($request->bearerToken() && ! $response->headers->has('Content-Disposition')
            && str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
