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
        // "Mayorista", cuyo slug real en producción es ProveedorTipo::SLUG_MAYORISTA
        // (cambiado a mano, no coincide con Str::slug('Mayorista')) — sin este
        // override el updateOrCreate de abajo no encuentra la fila real y crea
        // una duplicada con el slug viejo.
        $nombres = ['Hotel', 'Transporte', 'Mayorista', 'Guía', 'Operador de turismo', 'Atractivo / Actividad local'];

        foreach ($nombres as $nombre) {
            $slug = $nombre === 'Mayorista' ? ProveedorTipo::SLUG_MAYORISTA : Str::slug($nombre);

            ProveedorTipo::updateOrCreate(
                ['slug' => $slug],
                ['nombre' => $nombre, 'slug' => $slug, 'giro' => 'agencia_viajes', 'activo' => true]
            );
        }
    }
}
