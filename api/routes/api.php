<?php

use App\Modules\Authentication\Http\Controllers\AuthController;
use App\Modules\Authentication\Http\Controllers\PasswordResetController;
use App\Modules\Collections\Http\Controllers\CollectionController;
use App\Modules\Media\Http\Controllers\FileController;
use App\Modules\Media\Http\Controllers\MediaController;
use App\Modules\Media\Http\Controllers\UploadController;
use App\Modules\Tenants\Http\Controllers\TenantController;
use App\Modules\Tenants\Http\Controllers\TenantMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', fn () => response()->json(['status' => 'ok']));

    // Públicas (com limite de taxa por IP)
    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    // Entrega de arquivos por URL assinada e temporária (a permissão é checada quando a URL é emitida).
    Route::get('files/media/{media}/{kind}', [FileController::class, 'show'])
        ->whereIn('kind', ['thumb', 'preview', 'original', 'download'])
        ->middleware(['signed:relative', 'throttle:files'])
        ->name('files.media');

    // Autenticadas
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::apiResource('tenants', TenantController::class);

        Route::prefix('tenants/{tenant}')->group(function () {
            Route::get('members', [TenantMemberController::class, 'index']);
            Route::post('members', [TenantMemberController::class, 'store']);
            Route::patch('members/{userId}', [TenantMemberController::class, 'update']);
            Route::delete('members/{userId}', [TenantMemberController::class, 'destroy']);
        });

        // Recursos do projeto: o middleware "tenant" fixa o projeto atual (e o isolamento) antes dos controllers.
        Route::prefix('tenants/{tenant}')->middleware('tenant')->group(function () {
            Route::get('media', [MediaController::class, 'index']);
            Route::get('media/{media}', [MediaController::class, 'show']);
            Route::patch('media/{media}', [MediaController::class, 'update']);
            Route::delete('media/{media}', [MediaController::class, 'destroy']);
            Route::post('media/{media}/download-url', [MediaController::class, 'downloadUrl']);

            Route::middleware('throttle:uploads')->group(function () {
                Route::post('uploads', [UploadController::class, 'store']);
                Route::post('uploads/{session}/chunks/{index}', [UploadController::class, 'chunk'])->whereNumber('index');
                Route::post('uploads/{session}/complete', [UploadController::class, 'complete']);
                Route::delete('uploads/{session}', [UploadController::class, 'destroy']);
            });

            Route::get('collections', [CollectionController::class, 'index']);
            Route::post('collections', [CollectionController::class, 'store']);
            Route::patch('collections/{collection}', [CollectionController::class, 'update']);
            Route::delete('collections/{collection}', [CollectionController::class, 'destroy']);
            Route::post('collections/{collection}/media', [CollectionController::class, 'attach']);
            Route::delete('collections/{collection}/media/{media}', [CollectionController::class, 'detach']);
        });
    });
});
