<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use App\Enums\PaymentStatus;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => \App\Models\Order::factory(),
            'gateway' => 'simulated',
            'gateway_reference' => 'sim_' . Str::random(16),
            'status' => PaymentStatus::Pending,
            'amount_cents' => 5000,
            'currency' => 'BRL',
        ];
    }
}
