<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Product\DetractionCode;

class DetractionCodeSeeder extends Seeder
{
    public function run(): void
    {
        $detracciones = [
            // ===== BIENES (Anexo I y II) =====
            ['codigo' => '001', 'nombre' => 'Azúcar y melaza de caña', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '002', 'nombre' => 'Recursos hidrobiológicos', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],
            ['codigo' => '003', 'nombre' => 'Alcohol etílico', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '004', 'nombre' => 'Maíz amarillo duro', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],
            ['codigo' => '005', 'nombre' => 'Arena y piedra', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '006', 'nombre' => 'Residuos industriales', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '007', 'nombre' => 'Madera', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],
            ['codigo' => '008', 'nombre' => 'Oro gravado con el IGV', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '009', 'nombre' => 'Minerales metálicos no auríferos', 'tasa_porcentaje' => 10.00, 'tipo' => 'BIEN'],
            ['codigo' => '010', 'nombre' => 'Bienes exonerados del IGV', 'tasa_porcentaje' => 1.50, 'tipo' => 'BIEN'],
            ['codigo' => '011', 'nombre' => 'Carnes y despojos', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],
            ['codigo' => '012', 'nombre' => 'Caña de azúcar', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],
            ['codigo' => '013', 'nombre' => 'Arroz pilado', 'tasa_porcentaje' => 4.00, 'tipo' => 'BIEN'],

            // ===== SERVICIOS (Anexo 3 - Contratos de construcción y servicios) =====
            ['codigo' => '021', 'nombre' => 'Intermediación laboral', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '022', 'nombre' => 'Mantenimiento y reparación de bienes muebles', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '023', 'nombre' => 'Movimiento de carga', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '024', 'nombre' => 'Servicios de vigilancia', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '025', 'nombre' => 'Servicios de publicidad', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '026', 'nombre' => 'Servicios de transporte de bienes', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '027', 'nombre' => 'Servicios de hotelería', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '028', 'nombre' => 'Servicios de restaurantes', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '029', 'nombre' => 'Servicios de agencias de viaje', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '030', 'nombre' => 'Contratos de construcción', 'tasa_porcentaje' => 4.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '031', 'nombre' => 'Servicios de arquitectura e ingeniería', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '032', 'nombre' => 'Agentes marítimos y navieros', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '033', 'nombre' => 'Compañías aéreas', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
            ['codigo' => '034', 'nombre' => 'Agentes de carga internacional', 'tasa_porcentaje' => 10.00, 'tipo' => 'SERVICIO'],
        ];

        foreach ($detracciones as $detraccion) {
            DetractionCode::updateOrCreate(
                ['codigo' => $detraccion['codigo']],
                $detraccion
            );
        }
    }
}