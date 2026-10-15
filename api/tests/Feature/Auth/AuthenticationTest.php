<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $over = []): array
    {
        return array_merge([
            'name' => 'Maria Silva',
            'email' => 'maria@example.test',
            'password' => 'Senha-forte-123',
            'password_confirmation' => 'Senha-forte-123',
        ], $over);
    }

    public function test_user_can_register_and_receives_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]])
            ->assertJsonMissingPath('data.user.password');

        $this->assertDatabaseHas('users', ['email' => 'maria@example.test']);
        $this->assertTrue(Hash::check('Senha-forte-123', User::first()->password));
        $this->assertSame(26, strlen($response->json('data.user.id'))); // ULID público
    }

    public function test_register_rejects_weak_password_and_duplicate_email(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['password' => 'abc', 'password_confirmation' => 'abc']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        User::factory()->create(['email' => 'maria@example.test']);
        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_register_strips_html_from_name(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['name' => '<script>x</script>Maria']))->assertCreated();

        $this->assertSame('xMaria', User::first()->name);
    }

    public function test_user_can_login_and_use_token(): void
    {
        $user = User::factory()->create(['password' => 'Senha-forte-123']);

        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Senha-forte-123'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->ulid)->assertJsonPath('data.email', $user->email);
    }

    public function test_login_fails_with_same_message_for_wrong_password_and_unknown_email(): void
    {
        $user = User::factory()->create(['password' => 'Senha-forte-123']);

        $wrong = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'errada']);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'ninguem@example.test', 'password' => 'errada']);

        $wrong->assertStatus(422);
        $unknown->assertStatus(422);
        $this->assertSame($wrong->json('errors.email'), $unknown->json('errors.email'));
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        RateLimiter::clear('login:alvo@example.test|127.0.0.1');
        User::factory()->create(['email' => 'alvo@example.test', 'password' => 'Senha-forte-123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'alvo@example.test', 'password' => 'errada'])->assertStatus(422);
        }

        // Mesmo com a senha certa, a conta fica bloqueada temporariamente.
        $this->postJson('/api/v1/auth/login', ['email' => 'alvo@example.test', 'password' => 'Senha-forte-123'])->assertStatus(429);
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJson(['message' => 'Não autenticado.']);
        $this->getJson('/api/v1/tenants')->assertUnauthorized();
    }

    public function test_unauthenticated_request_without_accept_header_gets_json_401_not_500(): void
    {
        // Cliente que esquece "Accept: application/json" (curl, fetch) não pode causar erro 500.
        $this->get('/api/v1/auth/me')->assertStatus(401)->assertJson(['message' => 'Não autenticado.']);
        $this->get('/api/v1/tenants', ['Authorization' => 'Bearer token-inexistente'])->assertStatus(401);
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = User::factory()->create(['password' => 'Senha-forte-123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Senha-forte-123'])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_forgot_password_sends_mail_without_revealing_if_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ninguem@example.test']);

        $known->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_password_changes_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Senha-antiga-123']);
        $user->createToken('web');
        $token = Password::createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'Senha-nova-12345', 'password_confirmation' => 'Senha-nova-12345',
        ])->assertOk();

        $this->assertTrue(Hash::check('Senha-nova-12345', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_reset_password_rejects_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'token-falso', 'email' => $user->email,
            'password' => 'Senha-nova-12345', 'password_confirmation' => 'Senha-nova-12345',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }
}
