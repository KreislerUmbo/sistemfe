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
        // Mapa nombre → slug. Por defecto se deriva con Str::slug(), salvo
        // "Mayorista"/"Hotel", cuyo slug real en producción es
        // ProveedorTipo::SLUG_MAYORISTA/SLUG_ALOJAMIENTO (cambiados a mano,
        // no coinciden con Str::slug()) — sin este override el
        // updateOrCreate de abajo no encuentra la fila real y crea una
        // duplicada con el slug viejo (bug real: así quedó "Hotel" hasta
        // 2026-08-28, ver comentario en ProveedorTipo::SLUG_ALOJAMIENTO).
        $nombres = ['Hotel', 'Transporte', 'Mayorista', 'Guía', 'Operador de turismo', 'Atractivo / Actividad local'];
        $overrides = ['Mayorista' => ProveedorTipo::SLUG_MAYORISTA, 'Hotel' => ProveedorTipo::SLUG_ALOJAMIENTO];

        foreach ($nombres as $nombre) {
            $slug = $overrides[$nombre] ?? Str::slug($nombre);

            ProveedorTipo::updateOrCreate(
                ['slug' => $slug],
                ['nombre' => $nombre, 'slug' => $slug, 'giro' => 'agencia_viajes', 'activo' => true]
            );
        }
    }
}
