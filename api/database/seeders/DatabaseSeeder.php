<?php

namespace Database\Seeders;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(LicenseSeeder::class);

        // Dados de demonstração: somente em ambiente local, nunca em produção.
        if (! config('app.seed_demo_data') || ! app()->environment('local', 'testing')) {
            return;
        }

        $alice = User::firstOrCreate(['email' => 'alice@mediahub.test'], ['name' => 'Alice (SurfGestão)', 'password' => 'password']);
        $bruno = User::firstOrCreate(['email' => 'bruno@mediahub.test'], ['name' => 'Bruno (PilatesGestão)', 'password' => 'password']);

        $surf = Tenant::firstOrCreate(['slug' => 'surfgestao'], ['name' => 'SurfGestão', 'created_by' => $alice->id]);
        $pilates = Tenant::firstOrCreate(['slug' => 'pilatesgestao'], ['name' => 'PilatesGestão', 'created_by' => $bruno->id]);

        $surf->users()->syncWithoutDetaching([$alice->id => ['role' => TenantRole::Owner->value]]);
        $pilates->users()->syncWithoutDetaching([$bruno->id => ['role' => TenantRole::Owner->value]]);
    }
}
