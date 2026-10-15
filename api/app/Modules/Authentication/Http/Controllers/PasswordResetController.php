<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Authentication\Http\Requests\ForgotPasswordRequest;
use App\Modules\Authentication\Http\Requests\ResetPasswordRequest;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink(['email' => mb_strtolower($request->email)]);

        // Resposta idêntica exista o e-mail ou não.
        return response()->json(['message' => 'Se o e-mail estiver cadastrado, enviamos as instruções para redefinir a senha.']);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                $user->tokens()->delete(); // encerra todas as sessões abertas
                event(new PasswordReset($user));
                Audit::record('auth.password_reset', ['subject' => $user->ulid]);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['Link inválido ou expirado.']]);
        }

        return response()->json(['message' => 'Senha redefinida com sucesso.']);
    }
}
