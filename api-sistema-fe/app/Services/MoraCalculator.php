<?php

namespace App\Services;

use App\Models\Credit\Installment;
use App\Models\Sale\Sale;
use Carbon\Carbon;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.8.
// Cálculo de mora ON-THE-FLY — nunca se guarda como saldo fijo, se
// recalcula cada vez que se consulta (acá: en cada preview/confirm de
// pago, contra fecha_pago). Lo único que se congela alguna vez es
// payment_applications.monto_mora_cobrado, en el momento del cobro
// efectivo — eso lo hace el caller (CreditPaymentAllocator/
// CreditPaymentController), no este servicio.
//
// aplica_mora/tasa_mora/tipo_mora viven en 'sales' (§2.1), no por cuota —
// una venta cuotas_fijas aplica la MISMA configuración de mora a todas
// sus cuotas.
//
// Fórmula por tipo_mora (confirmada explícitamente con el usuario, no
// asumida):
//   fijo_por_cuota:        tasa_mora, monto FIJO una sola vez si está
//                          vencida — no escala con días de atraso.
//   porcentaje_diario:     tasa_mora% del saldo vencido POR CADA día de
//                          atraso — interés simple, no compuesto
//                          (dias_atraso × tasa% × saldo, no
//                          saldo×(1+tasa)^dias).
//   porcentaje_fijo_unico: tasa_mora% del saldo vencido, una sola vez,
//                          sin importar cuántos días lleva vencida.
class MoraCalculator
{
    // Mora de UNA cuota (venta credit_type='cuotas_fijas'), contra su
    // propia fecha_vencimiento. $saldoCapitalCentavos es el saldo de
    // CAPITAL de esa cuota (viene de CreditPaymentAllocator::
    // saldoCentavosInstallment() — este servicio no lo recalcula para no
    // duplicar esa consulta).
    public function calcularMoraInstallmentCentavos(Installment $cuota, int $saldoCapitalCentavos, Carbon $fechaReferencia): int
    {
        $venta = $cuota->sale;

        if (!$venta || !$venta->aplica_mora || $venta->tasa_mora === null || $saldoCapitalCentavos <= 0) {
            return 0;
        }

        $diasAtraso = $this->diasAtraso($cuota->fecha_vencimiento, $fechaReferencia);
        if ($diasAtraso <= 0) {
            return 0;
        }

        return $this->aplicarFormula($venta->tipo_mora, (float) $venta->tasa_mora, $saldoCapitalCentavos, $diasAtraso);
    }

    // Mora de una venta 'libre' completa, contra fecha_limite_pago (fecha
    // única, no hay cronograma). $saldoCapitalCentavos = sale.saldo_pendiente
    // en centavos.
    public function calcularMoraVentaLibreCentavos(Sale $venta, int $saldoCapitalCentavos, Carbon $fechaReferencia): int
    {
        if (!$venta->aplica_mora || $venta->tasa_mora === null || $saldoCapitalCentavos <= 0) {
            return 0;
        }

        // §3.1: sin fecha_limite_pago, mora en modo libre no tiene contra
        // qué calcularse — el frontend debería haber bloqueado el checkbox,
        // pero si de todos modos llega así, 0 en vez de error (dato
        // incompleto, no motivo para tumbar un cálculo de pago).
        if (!$venta->fecha_limite_pago) {
            return 0;
        }

        $diasAtraso = $this->diasAtraso($venta->fecha_limite_pago, $fechaReferencia);
        if ($diasAtraso <= 0) {
            return 0;
        }

        return $this->aplicarFormula($venta->tipo_mora, (float) $venta->tasa_mora, $saldoCapitalCentavos, $diasAtraso);
    }

    private function diasAtraso(Carbon $fechaLimite, Carbon $fechaReferencia): int
    {
        return (int) $fechaLimite->copy()->startOfDay()->diffInDays($fechaReferencia->copy()->startOfDay(), false);
    }

    private function aplicarFormula(?string $tipoMora, float $tasaMora, int $saldoCapitalCentavos, int $diasAtraso): int
    {
        return match ($tipoMora) {
            'fijo_por_cuota' => (int) round($tasaMora * 100),
            'porcentaje_diario' => (int) round($saldoCapitalCentavos * ($tasaMora / 100) * $diasAtraso),
            'porcentaje_fijo_unico' => (int) round($saldoCapitalCentavos * ($tasaMora / 100)),
            default => 0, // tipo_mora null/desconocido — sin config válida, no se cobra
        };
    }
}
