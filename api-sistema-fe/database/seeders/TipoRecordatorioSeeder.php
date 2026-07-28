<?php

namespace Database\Seeders;

use App\Models\AgenciaViajes\TipoRecordatorio;
use Illuminate\Database\Seeder;

// Catálogo de los 5 tipos de recordatorio del plan — plan-modulo-cotizaciones-reservas.md
// §8bis. Standalone, NO registrado en DatabaseSeeder ni en tenants:provision
// — mismo criterio ya usado por ReglaCancelacionSeeder: TENANT (no central),
// corre a mano por tenant cuando haga falta, cada agencia puede editar
// nombre/automatico después sin perder el punto de partida documentado.
class TipoRecordatorioSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['codigo' => 'pago_proveedor_pendiente', 'nombre' => 'Pago a proveedor pendiente', 'automatico' => true],
            ['codigo' => 'cumpleanos_cliente', 'nombre' => 'Cumpleaños de cliente', 'automatico' => true],
            ['codigo' => 'cotizacion_estancada', 'nombre' => 'Cotización estancada', 'automatico' => true],
            ['codigo' => 'documento_por_vencer', 'nombre' => 'Documento por vencer', 'automatico' => true],
            ['codigo' => 'personalizado', 'nombre' => 'Personalizado', 'automatico' => false],
        ];

        foreach ($tipos as $tipo) {
            TipoRecordatorio::updateOrCreate(
                ['codigo' => $tipo['codigo']],
                $tipo
            );
        }
    }
}
