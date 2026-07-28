<?php

namespace Database\Seeders;

use App\Models\AgenciaViajes\ReglaCancelacion;
use Illuminate\Database\Seeder;

// Regla general de la agencia (proveedor_id=null) — plan-modulo-cotizaciones-reservas.md
// §4.2. Standalone, NO registrado en DatabaseSeeder ni en tenants:provision
// — mismo criterio pendiente de automatizar que los seeders de Sesión 1
// (TaxConfigSeeder/DetractionCodeSeeder/ProveedorTipoSeeder/TemporadaSeeder,
// ver TODO.md), corre a mano por tenant cuando haga falta. A diferencia de
// esos 4 (catálogos CENTRALES), esta es una tabla TENANT — cada agencia
// puede tener sus propios valores una vez que edite estas filas; el seeder
// solo carga el punto de partida documentado en el plan.
class ReglaCancelacionSeeder extends Seeder
{
    public function run(): void
    {
        $reglas = [
            ['proveedor_id' => null, 'dias_min_antes' => 31, 'dias_max_antes' => null, 'porcentaje_reembolso' => 80],
            ['proveedor_id' => null, 'dias_min_antes' => 15, 'dias_max_antes' => 30, 'porcentaje_reembolso' => 50],
            ['proveedor_id' => null, 'dias_min_antes' => 0, 'dias_max_antes' => 14, 'porcentaje_reembolso' => 0],
        ];

        foreach ($reglas as $regla) {
            ReglaCancelacion::updateOrCreate(
                ['proveedor_id' => $regla['proveedor_id'], 'dias_min_antes' => $regla['dias_min_antes']],
                $regla
            );
        }
    }
}
