<?php

namespace Database\Factories;

use App\Models\ListingLicense;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ListingLicense> */
class ListingLicenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'listing_id' => \App\Models\Listing::factory(),
            'license_id' => \App\Models\License::factory(),
            'price_cents' => fake()->numberBetween(990, 29_900),
            'currency' => 'BRL',
        ];
    }
}
