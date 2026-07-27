<?php

namespace Database\Seeders;

use App\Models\AgenciaViajes\Temporada;
use Illuminate\Database\Seeder;

// Catálogo central — corre una sola vez contra db_tenant_central, no por
// tenant (mismo mecanismo pendiente de automatizar que TaxConfigSeeder/
// DetractionCodeSeeder, ver CLAUDE.md). plan-modulo-proveedores.md §2.6.
class TemporadaSeeder extends Seeder
{
    public function run(): void
    {
        $temporadas = [
            // 'fija' = mismo rango cada año (plan-modulo-proveedores.md §2.6).
            // Fiestas Patrias es fecha fija por Constitución (28-29 de julio,
            // no depende del calendario lunar) — 'fija', no 'movil'. El
            // ejemplo real de 'movil' es Semana Santa (fecha pascual, varía
            // cada año), no incluida en este seed inicial.
            ['nombre' => 'Temporada Alta', 'tipo' => 'fija', 'giro' => 'agencia_viajes'],
            ['nombre' => 'Fiestas Patrias', 'tipo' => 'fija', 'giro' => 'agencia_viajes'],
            ['nombre' => 'Navidad y Año Nuevo', 'tipo' => 'fija', 'giro' => 'agencia_viajes'],
        ];

        foreach ($temporadas as $temporada) {
            Temporada::updateOrCreate(
                ['nombre' => $temporada['nombre']],
                $temporada
            );
        }
    }
}
