<?php

namespace Database\Factories;

use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TenantDomain> */
class TenantDomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Tenant::factory(),
            'domain' => fake()->unique()->domainName(),
            'verification_token' => Str::random(40),
        ];
    }
}
