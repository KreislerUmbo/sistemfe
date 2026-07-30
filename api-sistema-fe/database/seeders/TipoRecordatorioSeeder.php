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
            // Sesión 11b4: catálogo agregado junto con configuracion_agencia.dias_aviso_vencimiento_cotizacion.
            // Sin disparador automático construido todavía (ver migración
            // 2026_07_30_110500_add_dias_aviso_vencimiento_cotizacion... para el detalle) —
            // 'automatico=true' documenta la intención de diseño, no una garantía de que
            // el sistema ya genere esta fila solo.
            ['codigo' => 'cotizacion_por_vencer', 'nombre' => 'Cotización por vencer', 'automatico' => true],
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
