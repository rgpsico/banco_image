<?php

namespace Database\Factories;

use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OrderItem> */
class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => \App\Models\Order::factory(),
            'listing_id' => \App\Models\Listing::factory(),
            'license_id' => \App\Models\License::factory(),
            'seller_id' => \App\Models\User::factory(),
            'title_snapshot' => fake()->sentence(3),
            'unit_price_cents' => 5000,
            'commission_bps' => 2000,
            'platform_fee_cents' => 1000,
            'seller_earning_cents' => 4000,
        ];
    }
}
