<?php

use App\Modules\Tenants\Http\Middleware\ResolveTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'tenant.key' => \App\Modules\Tenants\Http\Middleware\AuthenticateApiKey::class,
            'seller' => \App\Modules\Marketplace\Http\Middleware\EnsureSeller::class,
            'platform.admin' => \App\Modules\Marketplace\Http\Middleware\EnsurePlatformAdmin::class,
        ]);
        // API pura: nunca redireciona visitantes para uma tela de login (não existe); responde 401 em JSON.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trustProxies(at: '*');
        $middleware->api(append: [\App\Http\Middleware\SecurityHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Respostas de erro padronizadas: { "message": "...", "errors"?: {...} }. Sem nomes de models, SQL ou caminhos.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        });
        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            return response()->json(['message' => 'Você não tem permissão para esta ação.'], 403);
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            return response()->json(['message' => 'Recurso não encontrado.'], 404);
        });
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            return response()->json(['message' => 'Muitas requisições. Tente novamente em instantes.'], 429, $e->getHeaders());
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'Erro na requisição.'], $e->getStatusCode(), $e->getHeaders());
            }
        });
    })->create();
