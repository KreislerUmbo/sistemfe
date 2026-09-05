<?php

namespace Database\Seeders;

use App\Models\AgenciaViajes\AfiliacionTurismo;
use Illuminate\Database\Seeder;

// Catálogo central — corre una sola vez contra la base central, no por
// tenant (mismo mecanismo manual pendiente de automatizar que
// ProveedorTipoSeeder/TaxConfigSeeder/DetractionCodeSeeder, ver CLAUDE.md).
// plan-mejora-pdf-cotizacion-cliente.md §4.3.
//
// logo_path queda null a propósito: no se fabrica el logo oficial de
// Mincetur/Apavit/PromPerú sin el archivo real (marca de terceros) — pedir
// los archivos reales y cargarlos después con
// ConfiguracionAgenciaPdfController o directo por Storage. La franja de
// afiliaciones cae al nombre en texto mientras tanto.
class AfiliacionTurismoSeeder extends Seeder
{
    public function run(): void
    {
        $afiliaciones = [
            ['codigo' => 'mincetur', 'nombre' => 'MINCETUR'],
            ['codigo' => 'apavit', 'nombre' => 'APAVIT'],
            ['codigo' => 'promperu', 'nombre' => 'PromPerú'],
        ];

        foreach ($afiliaciones as $afiliacion) {
            AfiliacionTurismo::updateOrCreate(
                ['codigo' => $afiliacion['codigo']],
                $afiliacion
            );
        }
    }
}
