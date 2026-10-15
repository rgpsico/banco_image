<?php

namespace Database\Factories;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\ListingStatus;

/** @extends Factory<Listing> */
class ListingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'seller_id' => \App\Models\User::factory(),
            'tenant_id' => \App\Models\Tenant::factory(),
            'media_id' => \App\Models\Media::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(['praia', 'natureza', 'pessoas', 'cidade']),
            'tags' => fake()->words(4),
            'status' => ListingStatus::Draft,
        ];
    }
}
