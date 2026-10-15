<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_security_headers(): void
    {
        $res = $this->getJson('/api/v1/health')->assertOk()->assertJson(['status' => 'ok']);

        $res->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('X-Powered-By');
    }

    public function test_private_json_is_never_cacheable(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $res = $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_routes_config_and_views_can_be_cached_for_production(): void
    {
        // Roda os mesmos comandos de otimização do deploy; falha se alguma rota/config não for cacheável.
        try {
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('route:cache'), \Illuminate\Support\Facades\Artisan::output());
            $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('config:cache'), \Illuminate\Support\Facades\Artisan::output());
        } finally {
            \Illuminate\Support\Facades\Artisan::call('route:clear');
            \Illuminate\Support\Facades\Artisan::call('config:clear');
        }
    }
}
