<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product\TaxConfig;

class TaxConfigSeeder extends Seeder
{
    public function run(): void
    {
        $taxes = [
            // ===== IGV =====
            [
                'nombre' => 'IGV General',
                'codigo_sunat' => '1000',
                'tipo' => 'IGV',
                'tasa_porcentaje' => 18.00,
                'fecha_inicio_vigencia' => '2020-01-01',
                'fecha_fin_vigencia' => null,
                'ubigeos_aplicables' => null,
                'es_activo' => true,
            ],
            [
                'nombre' => 'IGV Amazonía (Ley 27037)',
                'codigo_sunat' => '1000',
                'tipo' => 'IGV',
                'tasa_porcentaje' => 5.00,
                'fecha_inicio_vigencia' => '2020-01-01',
                'fecha_fin_vigencia' => null,
                'ubigeos_aplicables' => json_encode(['01', '16', '17', '22', '25']),
                'es_activo' => true,
            ],
            [
                'nombre' => 'IGV MYPE Restaurantes y Hoteles',
                'codigo_sunat' => '1000',
                'tipo' => 'IGV',
                'tasa_porcentaje' => 10.50,
                'fecha_inicio_vigencia' => '2025-01-01',
                'fecha_fin_vigencia' => '2026-12-31',
                'ubigeos_aplicables' => null,
                'es_activo' => true,
            ],

            // ===== ICBPER (Bolsa plástica) =====
            [
                'nombre' => 'ICBPER Bolsa Plástica',
                'codigo_sunat' => '2000',
                'tipo' => 'ICBPER',
                'tasa_porcentaje' => 0.50,
                'fecha_inicio_vigencia' => '2023-01-01',
                'fecha_fin_vigencia' => null,
                'ubigeos_aplicables' => null,
                'es_activo' => true,
            ],

            // ===== IVAP (Arroz pilado) =====
            [
                'nombre' => 'IVAP Arroz Pilado',
                'codigo_sunat' => '1016',
                'tipo' => 'IVAP',
                'tasa_porcentaje' => 4.00,
                'fecha_inicio_vigencia' => '2020-01-01',
                'fecha_fin_vigencia' => null,
                'ubigeos_aplicables' => null,
                'es_activo' => true,
            ],

            // ===== ISC (Impuesto Selectivo al Consumo) =====
            [
                'nombre' => 'ISC General',
                'codigo_sunat' => '3000',
                'tipo' => 'ISC',
                'tasa_porcentaje' => 0.00,
                'fecha_inicio_vigencia' => '2020-01-01',
                'fecha_fin_vigencia' => null,
                'ubigeos_aplicables' => null,
                'es_activo' => true,
            ],
        ];

        foreach ($taxes as $tax) {
            TaxConfig::updateOrCreate(
                ['nombre' => $tax['nombre']],
                $tax
            );
        }
    }
}