<?php

use App\Modules\Authentication\Http\Controllers\AuthController;
use App\Modules\Authentication\Http\Controllers\PasswordResetController;
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
    });
});
