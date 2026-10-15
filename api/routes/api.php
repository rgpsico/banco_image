<?php

use App\Modules\Authentication\Http\Controllers\AuthController;
use App\Modules\Authentication\Http\Controllers\PasswordResetController;
use App\Modules\Collections\Http\Controllers\CollectionController;
use App\Modules\Downloads\Http\Controllers\PurchaseController;
use App\Modules\Marketplace\Http\Controllers\AdminUserController;
use App\Modules\Marketplace\Http\Controllers\CatalogController;
use App\Modules\Orders\Http\Controllers\AdminOrderController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Payments\Http\Controllers\WebhookController;
use App\Modules\Marketplace\Http\Controllers\ModerationController;
use App\Modules\Marketplace\Http\Controllers\ReportController;
use App\Modules\Marketplace\Http\Controllers\SellerDashboardController;
use App\Modules\Marketplace\Http\Controllers\SellerController;
use App\Modules\Marketplace\Http\Controllers\SellerListingController;
use App\Modules\Media\Http\Controllers\FileController;
use App\Modules\Media\Http\Controllers\MediaController;
use App\Modules\Media\Http\Controllers\UploadController;
use App\Modules\Tenants\Http\Controllers\TenantController;
use App\Modules\Tenants\Http\Controllers\TenantMemberController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health', \App\Http\Controllers\HealthController::class);

    // Públicas (com limite de taxa por IP)
    Route::middleware('throttle:auth')->prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('reset-password', [PasswordResetController::class, 'reset']);
    });

    // ---- Marketplace: catálogo PÚBLICO (sem login) ----
    Route::prefix('market')->middleware('throttle:market')->group(function () {
        Route::get('listings', [CatalogController::class, 'index']);
        Route::get('listings/{listing}', [CatalogController::class, 'show']);
        Route::get('listings/{listing}/image/{size}', [CatalogController::class, 'image'])->whereIn('size', ['thumb', 'large'])->withoutMiddleware('throttle:market')->middleware('throttle:files');
        Route::get('categories', [CatalogController::class, 'categories']);
        Route::get('licenses', [CatalogController::class, 'licenses']);
        Route::get('sellers/{slug}', [CatalogController::class, 'seller']);
    });

    // Webhooks de pagamento (sem login: autenticados pela assinatura do provedor).
    Route::post('webhooks/{gateway}', [WebhookController::class, 'handle'])->middleware('throttle:300,1')->whereAlpha('gateway');

    // Download de compra: link assinado e curto emitido após checar pagamento e limites.
    Route::get('files/purchase/{grant}/download', [FileController::class, 'purchase'])->middleware(['signed:relative', 'throttle:files'])->name('files.purchase');

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

        // ---- Compras ----
        Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:30,1');
        Route::get('orders', [OrderController::class, 'index']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::post('orders/{order}/pay', [OrderController::class, 'pay'])->middleware('throttle:30,1');
        Route::post('orders/{order}/simulate-payment', [OrderController::class, 'simulatePayment'])->middleware('throttle:30,1');
        Route::get('purchases', [PurchaseController::class, 'index']);
        Route::post('purchases/{item}/download-url', [PurchaseController::class, 'downloadUrl'])->middleware('throttle:60,1');

        // ---- Marketplace: área do vendedor ----
        Route::get('seller/status', [SellerController::class, 'status']);
        Route::post('seller/join', [SellerController::class, 'join']);

        Route::middleware('seller')->prefix('seller')->group(function () {
            Route::get('profile', [SellerController::class, 'show']);
            Route::patch('profile', [SellerController::class, 'update']);

            Route::get('listings', [SellerListingController::class, 'index']);
            Route::post('listings', [SellerListingController::class, 'store']);
            Route::get('listings/{listing}', [SellerListingController::class, 'show']);
            Route::patch('listings/{listing}', [SellerListingController::class, 'update']);
            Route::post('listings/{listing}/submit', [SellerListingController::class, 'submit']);
            Route::post('listings/{listing}/unpublish', [SellerListingController::class, 'unpublish']);
            Route::delete('listings/{listing}', [SellerListingController::class, 'destroy']);

            Route::get('stats', [SellerDashboardController::class, 'stats']);
            Route::get('sales', [SellerDashboardController::class, 'sales']);
            Route::get('reports/commissions', [SellerDashboardController::class, 'commissions']);
        });

        Route::post('market/listings/{listing}/reports', [ReportController::class, 'store'])->middleware('throttle:10,60');

        // ---- Moderação (admin da plataforma) ----
        Route::middleware('platform.admin')->prefix('admin')->group(function () {
            Route::get('listings', [ModerationController::class, 'index']);
            Route::get('listings/{listing}', [ModerationController::class, 'show']);
            Route::post('listings/{listing}/approve', [ModerationController::class, 'approve']);
            Route::post('listings/{listing}/reject', [ModerationController::class, 'reject']);
            Route::post('listings/{listing}/suspend', [ModerationController::class, 'suspend']);
            Route::get('reports', [ReportController::class, 'index']);
            Route::post('reports/{report}/resolve', [ReportController::class, 'resolve']);
            Route::get('sellers', [ReportController::class, 'sellers']);
            Route::patch('sellers/{slug}', [ReportController::class, 'updateSeller']);
            Route::get('users', [AdminUserController::class, 'index']);
            Route::patch('users/{user}', [AdminUserController::class, 'update'])->middleware('throttle:30,1');
            Route::get('orders', [AdminOrderController::class, 'index']);
            Route::post('orders/{order}/refund', [AdminOrderController::class, 'refund'])->middleware('throttle:30,1');
            Route::get('payouts', [AdminOrderController::class, 'payouts']);
            Route::post('payouts/{slug}/mark-paid', [AdminOrderController::class, 'markPaid']);
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
