<?php

namespace Database\Factories;

use App\Models\UploadSession;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\UploadSessionStatus;

/** @extends Factory<UploadSession> */
class UploadSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Tenant::factory(),
            'user_id' => \App\Models\User::factory(),
            'status' => UploadSessionStatus::Pending,
            'original_name' => fake()->word() . '.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1_000_000,
            'chunk_size' => 1_000_000,
            'total_chunks' => 1,
            'expires_at' => now()->addHours(6),
        ];
    }
}
