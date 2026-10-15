<?php

namespace Database\Factories;

use App\Models\SellerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<SellerProfile> */
class SellerProfileFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'user_id' => \App\Models\User::factory(),
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 99999),
            'display_name' => $name,
            'bio' => fake()->sentence(),
            'status' => 'active',
            'terms_accepted_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
