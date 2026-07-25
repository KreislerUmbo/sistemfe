<?php

namespace App\Services;

use App\Models\Sale\Sale;
use Carbon\Carbon;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.11, §4 (Fase 7).
// Arma la fila de "estado de cuenta" de UNA venta abierta — es el cálculo
// que comparten los 3 endpoints de lectura del módulo (mismo criterio que
// el plan ya deja explícito en §3.11: "no hace falta duplicar lógica de
// cálculo, solo dos formas de presentar el mismo query"):
//   - CreditPaymentController::creditSummary()      → GET /clients/{client}/credit-summary
//   - CreditReceivablesController::creditSales()     → GET /credit-sales (vista B)
//   - CreditReceivablesController::creditSummaryList() → GET /clients/credit-summary-list (vista A)
//
// Filtro de ventas elegibles en TODOS los callers: saldo_pendiente > 0, SIN
// exigir condicion_pago='credito' — mismo criterio ya cerrado en
// CreditPaymentAllocator (§3.11/§9): ventas 'contado' con deuda informal
// (debt/paid_out legado) deben seguir apareciendo en la cartera.
//
// Solo LEE, no persiste nada — no necesita locks.
class CreditSummaryCalculator
{
    // ⚠️ Umbral de "por_vencer" (días hasta el próximo vencimiento) — el
    // plan (§3.11) menciona el estado pero NO fija el número de días.
    // 7 es una asunción documentada, no una confirmación del usuario:
    // ajustar acá si se define un valor de negocio distinto.
    private const DIAS_POR_VENCER = 7;

    protected $moraCalculator;
    protected $allocator;

    public function __construct(MoraCalculator $moraCalculator, CreditPaymentAllocator $allocator)
    {
        $this->moraCalculator = $moraCalculator;
        $this->allocator = $allocator;
    }

    // Todas las ventas abiertas de un cliente (mismo filtro que
    // CreditPaymentAllocator::allocate()), ordenadas por antigüedad.
    public function ventasAbiertasCliente(int $clientId)
    {
        return Sale::where('client_id', $clientId)
            ->where('saldo_pendiente', '>', 0)
            ->orderBy('date')
            ->get();
    }

    // Fila de estado de cuenta de UNA venta. $fechaReferencia default "hoy"
    // si no se pasa (mismo criterio que MoraCalculator/CreditPaymentAllocator).
    public function resumenVenta(Sale $venta, ?Carbon $fechaReferencia = null): array
    {
        $fechaReferencia ??= now();
        $hoy = $fechaReferencia->copy()->startOfDay();

        $saldoCentavos = (int) round((float) $venta->saldo_pendiente * 100);
        $cuotasVencidas = 0;
        $proximaCuotaVencimiento = null;
        $moraCentavos = 0;

        if ($venta->credit_type === 'cuotas_fijas') {
            $cuotas = $venta->installments()
                ->whereIn('estado', ['pendiente', 'parcial', 'vencida'])
                ->orderBy('fecha_vencimiento')
                ->get();

            foreach ($cuotas as $cuota) {
                $saldoCuotaCentavos = $this->allocator->saldoCentavosInstallment($cuota);
                if ($saldoCuotaCentavos <= 0) {
                    continue;
                }

                if ($cuota->fecha_vencimiento->lt($hoy)) {
                    $cuotasVencidas++;
                } elseif ($proximaCuotaVencimiento === null) {
                    // Primera cuota con saldo real y vencimiento >= hoy, en
                    // orden de fecha_vencimiento — la "próxima por vencer".
                    $proximaCuotaVencimiento = $cuota->fecha_vencimiento;
                }

                $moraCentavos += $this->moraCalculator->calcularMoraInstallmentCentavos(
                    $cuota,
                    $saldoCuotaCentavos,
                    $fechaReferencia
                );
            }
        } elseif ($venta->credit_type === 'libre') {
            $moraCentavos = $this->moraCalculator->calcularMoraVentaLibreCentavos($venta, $saldoCentavos, $fechaReferencia);

            if ($venta->fecha_limite_pago) {
                if ($venta->fecha_limite_pago->lt($hoy)) {
                    $cuotasVencidas = 1; // venta 'libre' no tiene cronograma — vencida como unidad completa
                } else {
                    $proximaCuotaVencimiento = $venta->fecha_limite_pago;
                }
            }
        }
        // credit_type null (venta 'contado' con deuda informal vía
        // debt/paid_out legado, §9): sin cronograma ni fecha límite propia
        // de este módulo — no hay contra qué calcular vencimiento/mora,
        // queda 'al_dia' por diseño (mismo criterio que MoraCalculator
        // cuando falta fecha_limite_pago).

        return [
            'sale_id' => $venta->id,
            'client_id' => $venta->client_id,
            'n_operacion' => $venta->n_operacion,
            // 'date' NO tiene cast a Carbon en Sale.php (a diferencia de
            // fecha_vencimiento/fecha_limite_pago) — es un string plano
            // 'Y-m-d' ya, optional()->format() sobre un string devuelve
            // null en silencio (Optional no tiene format()).
            'date' => $venta->date,
            'condicion_pago' => $venta->condicion_pago,
            'credit_type' => $venta->credit_type,
            'saldo_pendiente' => round($saldoCentavos / 100, 2),
            'mora_acumulada' => round($moraCentavos / 100, 2),
            'cuotas_vencidas' => $cuotasVencidas,
            'proxima_cuota_vencimiento' => optional($proximaCuotaVencimiento)->format('Y-m-d'),
            'estado' => $this->determinarEstado($cuotasVencidas, $proximaCuotaVencimiento, $hoy),
        ];
    }

    private function determinarEstado(int $cuotasVencidas, ?Carbon $proximaFecha, Carbon $hoy): string
    {
        if ($cuotasVencidas > 0) {
            return 'vencida';
        }

        if ($proximaFecha && $hoy->diffInDays($proximaFecha, false) <= self::DIAS_POR_VENCER) {
            return 'por_vencer';
        }

        return 'al_dia';
    }
}
