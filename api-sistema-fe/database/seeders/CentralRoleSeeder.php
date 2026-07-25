<?php

namespace Database\Seeders;

use App\Models\Central\CentralRole;
use Illuminate\Database\Seeder;

class CentralRoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['superadmin', 'soporte', 'solo-lectura'] as $name) {
            CentralRole::firstOrCreate(['name' => $name]);
        }
    }
}
