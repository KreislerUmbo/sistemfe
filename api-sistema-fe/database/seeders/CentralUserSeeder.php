<?php

namespace Database\Seeders;

use App\Models\Central\CentralRole;
use App\Models\Central\CentralUser;
use Illuminate\Database\Seeder;
use RuntimeException;

class CentralUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('CENTRAL_ADMIN_EMAIL');
        $password = env('CENTRAL_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException(
                'CENTRAL_ADMIN_EMAIL y CENTRAL_ADMIN_PASSWORD deben estar seteados en .env ' .
                'antes de correr este seeder — sin credenciales hardcodeadas por defecto.'
            );
        }

        $admin = CentralUser::firstOrCreate(
            ['email' => $email],
            [
                'name' => env('CENTRAL_ADMIN_NAME', 'Superadmin'),
                'password' => $password,
            ]
        );

        $superadmin = CentralRole::where('name', 'superadmin')->firstOrFail();

        $admin->roles()->syncWithoutDetaching([$superadmin->id]);
    }
}
