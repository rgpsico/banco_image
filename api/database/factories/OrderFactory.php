<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use App\Enums\OrderStatus;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'buyer_id' => \App\Models\User::factory(),
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 0,
            'platform_fee_cents' => 0,
            'total_cents' => 0,
            'currency' => 'BRL',
        ];
    }
}
