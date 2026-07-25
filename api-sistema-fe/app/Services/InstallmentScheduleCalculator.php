<?php

namespace App\Services;

use Carbon\Carbon;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §2.2/§3.1.
// Genera el cronograma SUGERIDO para el preview (nunca persiste). La
// periodicidad es solo insumo para calcular fecha_vencimiento — nunca se
// guarda (§2.2, "la periodicidad ... no se persiste").
//
// Criterio de redondeo (decisión de esta fase, no asumida en silencio):
// se trabaja en centavos enteros para que la suma SIEMPRE cuadre exacto
// con el monto total, sin arrastre de error de punto flotante.
//   totalCentavos = round(montoTotal * 100)
//   cada cuota recibe intdiv(totalCentavos, numCuotas)
//   la ÚLTIMA cuota además recibe el resto de esa división entera
//   (totalCentavos % numCuotas), absorbiendo ahí toda la diferencia.
class InstallmentScheduleCalculator
{
    public function generar(float $montoTotal, int $numCuotas, string $periodicidad, Carbon $fechaAnchor): array
    {
        $totalCentavos = (int) round($montoTotal * 100);
        $baseCentavos = intdiv($totalCentavos, $numCuotas);
        $restoCentavos = $totalCentavos % $numCuotas;

        $cronograma = [];

        for ($i = 1; $i <= $numCuotas; $i++) {
            $montoCentavos = $baseCentavos + ($i === $numCuotas ? $restoCentavos : 0);

            $cronograma[] = [
                'numero_cuota' => $i,
                'monto_programado' => round($montoCentavos / 100, 2),
                'fecha_vencimiento' => $this->fechaSugerida($fechaAnchor, $periodicidad, $i)?->toDateString(),
            ];
        }

        return $cronograma;
    }

    // 'personalizada' no tiene un intervalo definido en el plan — se
    // devuelve null y el cajero completa cada fecha a mano en el frontend
    // antes de confirmar (el preview sigue siendo editable, §3.1 paso 3).
    private function fechaSugerida(Carbon $anchor, string $periodicidad, int $numeroCuota): ?Carbon
    {
        return match ($periodicidad) {
            'mensual' => (clone $anchor)->addMonthsNoOverflow($numeroCuota),
            'quincenal' => (clone $anchor)->addDays(15 * $numeroCuota),
            'semanal' => (clone $anchor)->addWeeks($numeroCuota),
            default => null, // 'personalizada' u otro valor no reconocido
        };
    }
}
