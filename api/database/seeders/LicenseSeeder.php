<?php

namespace Database\Seeders;

use App\Models\License;
use Illuminate\Database\Seeder;

class LicenseSeeder extends Seeder
{
    public function run(): void
    {
        $licenses = [
            ['code' => 'personal', 'name' => 'Pessoal', 'allows_commercial' => false,
                'description' => 'Uso pessoal e não comercial.',
                'terms' => 'Uso pessoal, sem fins comerciais. Proibida a revenda ou redistribuição do arquivo.'],
            ['code' => 'commercial', 'name' => 'Comercial', 'allows_commercial' => true,
                'description' => 'Uso comercial em sites, redes sociais e materiais de divulgação.',
                'terms' => 'Uso comercial em mídias digitais e impressas de divulgação. Proibida a revenda do arquivo em si e o uso como marca registrada.'],
            ['code' => 'extended', 'name' => 'Estendida', 'allows_commercial' => true,
                'description' => 'Uso comercial ampliado, inclusive em produtos para revenda.',
                'terms' => 'Uso comercial ampliado, inclusive em produtos vendidos a terceiros. Proibida a redistribuição do arquivo original.'],
        ];

        foreach ($licenses as $license) {
            License::updateOrCreate(['code' => $license['code']], $license + ['is_active' => true]);
        }
    }
}
