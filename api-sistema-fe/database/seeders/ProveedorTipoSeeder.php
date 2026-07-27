<?php

namespace Database\Seeders;

use App\Models\AgenciaViajes\ProveedorTipo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

// Catálogo central — corre una sola vez contra db_tenant_central, no por
// tenant (mismo mecanismo pendiente de automatizar que TaxConfigSeeder/
// DetractionCodeSeeder, ver CLAUDE.md). plan-modulo-proveedores.md §2.6.
class ProveedorTipoSeeder extends Seeder
{
    public function run(): void
    {
        $nombres = ['Hotel', 'Transporte', 'Mayorista', 'Guía'];

        foreach ($nombres as $nombre) {
            $slug = Str::slug($nombre);

            ProveedorTipo::updateOrCreate(
                ['slug' => $slug],
                ['nombre' => $nombre, 'slug' => $slug, 'giro' => 'agencia_viajes', 'activo' => true]
            );
        }
    }
}
