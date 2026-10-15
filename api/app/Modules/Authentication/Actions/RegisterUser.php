<?php

namespace App\Modules\Authentication\Actions;

use App\Models\User;
use App\Support\Audit;

class RegisterUser
{
    /** @param array{name: string, email: string, password: string} $data */
    public function handle(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // cast "hashed" no model
        ]);

        Audit::record('auth.registered', ['subject' => $user->ulid]);

        return $user;
    }
}
