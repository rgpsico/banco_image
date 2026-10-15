<?php

namespace Database\Factories;

use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\MediaStatus;
use App\Enums\MediaType;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Tenant::factory(),
            'uploaded_by' => \App\Models\User::factory(),
            'type' => MediaType::Image,
            'original_name' => $name = fake()->word() . '.jpg',
            'display_name' => $name,
            'disk' => 'media',
            'path' => 'tenants/test/' . Str::ulid() . '.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size_bytes' => fake()->numberBetween(50_000, 5_000_000),
            'checksum_sha256' => hash('sha256', Str::random(20)),
            'width' => 1600,
            'height' => 1066,
            'status' => MediaStatus::Ready,
        ];
    }
}
