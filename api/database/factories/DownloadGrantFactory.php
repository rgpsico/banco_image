<?php

namespace Database\Factories;

use App\Models\DownloadGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DownloadGrant> */
class DownloadGrantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => \App\Models\OrderItem::factory(),
            'user_id' => \App\Models\User::factory(),
            'max_downloads' => 5,
            'downloads_count' => 0,
            'expires_at' => now()->addDays(30),
        ];
    }
}
