<?php

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\ListingReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ListingReport> */
class ListingReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'listing_id' => \App\Models\Listing::factory(),
            'reporter_id' => \App\Models\User::factory(),
            'reason' => ReportReason::Copyright,
            'details' => fake()->sentence(),
            'status' => ReportStatus::Open,
        ];
    }
}
