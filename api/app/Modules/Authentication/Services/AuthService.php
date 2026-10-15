<?php

namespace App\Modules\Authentication\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthService
{
    private const MAX_ATTEMPTS = 5;

    /** @return array{token: string, user: User} */
    public function login(string $email, string $password, string $ip, ?string $device = null): array
    {
        $key = 'login:' . mb_strtolower($email) . '|' . $ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Audit::record('auth.login_throttled', ['email_hash' => sha1($email)]);

            throw ValidationException::withMessages([
                'email' => ['Muitas tentativas. Tente novamente em ' . RateLimiter::availableIn($key) . ' segundos.'],
            ])->status(429);
        }

        $user = User::where('email', $email)->first();

        // Mesma resposta para e-mail inexistente e senha errada: não revela quais e-mails existem.
        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60);
            Audit::record('auth.login_failed', ['email_hash' => sha1($email)]);

            throw ValidationException::withMessages(['email' => ['Credenciais inválidas.']]);
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();

        $token = $user->createToken($device ?: 'web', ['*'], now()->addDays((int) config('sanctum.token_days', 7)));
        Audit::record('auth.login', ['subject' => $user->ulid]);

        return ['token' => $token->plainTextToken, 'user' => $user];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        Audit::record('auth.logout', ['subject' => $user->ulid]);
    }
}
