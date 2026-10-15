<?php

namespace App\Providers;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Tenant;
use App\Modules\Collections\Policies\CollectionPolicy;
use App\Modules\Media\Policies\MediaPolicy;
use App\Modules\Storage\Contracts\MediaStorage;
use App\Modules\Storage\Services\DiskMediaStorage;
use App\Modules\Tenants\Policies\TenantPolicy;
use App\Modules\Tenants\Support\CurrentTenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);

        // Provedor de armazenamento: troque MEDIA_DISK (ex.: para um disco S3/R2) sem alterar regras de negócio.
        $this->app->bind(MediaStorage::class, fn () => new DiskMediaStorage(config('mediahub.disk')));
    }

    public function boot(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Media::class, MediaPolicy::class);
        Gate::policy(Collection::class, CollectionPolicy::class);

        // {tenant} nas rotas só resolve entre os projetos DO usuário autenticado: qualquer outro vira 404
        // (não revela se o projeto existe).
        Route::bind('tenant', function (string $value) {
            $user = auth('sanctum')->user();
            abort_unless($user, 401);

            return $user->tenants()->where('tenants.ulid', $value)->firstOrFail();
        });

        // O e-mail de recuperação aponta para o front-end (Vue), não para a API.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('app.frontend_url'), '/') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(1200)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('market', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('files', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
