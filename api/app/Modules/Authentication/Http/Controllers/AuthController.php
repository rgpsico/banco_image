<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Actions\RegisterUser;
use App\Modules\Authentication\Http\Requests\LoginRequest;
use App\Modules\Authentication\Http\Requests\RegisterRequest;
use App\Modules\Authentication\Http\Resources\UserResource;
use App\Modules\Authentication\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, RegisterUser $action, AuthService $auth): JsonResponse
    {
        $user = $action->handle($request->validated());
        $session = $auth->login($user->email, $request->input('password'), $request->ip(), $request->input('device_name'));

        return response()->json([
            'data' => ['user' => new UserResource($user), 'token' => $session['token']],
        ], 201);
    }

    public function login(LoginRequest $request, AuthService $auth): JsonResponse
    {
        $session = $auth->login($request->email, $request->password, $request->ip(), $request->device_name);

        return response()->json([
            'data' => ['user' => new UserResource($session['user']), 'token' => $session['token']],
        ]);
    }

    public function logout(Request $request, AuthService $auth): JsonResponse
    {
        $auth->logout($request->user());

        return response()->json(null, 204);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}
