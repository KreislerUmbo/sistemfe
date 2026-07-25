<?php

namespace App\Services;

use App\Models\Client\Client;
use App\Models\Credit\Installment;
use App\Models\Sale\Sale;
use Carbon\Carbon;

// Módulo Amortizaciones — plan-modulo-amortizaciones.md §3.4, §3.8 (Fase 6).
// Algoritmo FIFO de reparto de un pago entre las ventas/cuotas abiertas de
// un cliente. Solo LEE — no persiste nada. CreditPaymentController::store()
// NO vuelve a correr este algoritmo: recibe el array ya confirmado/editado
// por el cajero y solo lo valida/persiste (mismo criterio que
// CreditInstallmentController::store() con el cronograma).
//
// Filtro de ventas elegibles: saldo_pendiente > 0, SIN exigir
// condicion_pago='credito' — corrección ya cerrada en Fase 1 (§3.11/§9):
// hay ventas 'contado' con deuda real vía el mecanismo legado
// debt/paid_out que deben ser cobrables igual por este módulo.
//
// Redondeo: centavos enteros en todo el cálculo, mismo criterio que
// InstallmentScheduleCalculator (Fase 3) — nunca floats.
//
// Mora (Fase 6, decisión confirmada con el usuario — NO es la
// recomendación por defecto que yo había sugerido): dentro de cada bucket
// (cuota o venta libre), el pago cubre CAPITAL primero y MORA después —
// más favorable al cliente, reduce la base sobre la que sigue corriendo
// mora futura. saldo_pendiente/saldo_antes/saldo_despues y monto_aplicado
// en 'aplicaciones' siguen representando SOLO capital, sin cambios de
// significado — mora vive en campos nuevos paralelos (mora_antes/
// monto_mora_aplicado) para no romper nada que ya dependía de esos campos
// (Fase 4/5).
//
// ⚠️ Consecuencia real de este orden (documentada, no "arreglada" con un
// parche que no resolvería nada de fondo): si un solo pago alcanza para
// saldar el capital COMPLETO de una cuota pero no cubre (o cubre solo en
// parte) la mora calculada en ese mismo momento, esa cuota pasa a
// 'pagada' y sale de toda selección futura de pago — y aunque no
// saliera, MoraCalculator devuelve 0 en cuanto saldoCapital<=0 (es la
// fórmula, no un bug: mora depende de capital vigente, §3.8 exige que
// nunca se persista como saldo aparte). Esa mora puntual queda
// efectivamente perdonada. Con mora-primero esto no podía pasar. Resolver
// esto de fondo requeriría una columna de mora pendiente persistida, que
// contradice el diseño on-the-fly del plan — se deja documentado, no
// implementado, a la espera de que el usuario decida si hace falta.
class CreditPaymentAllocator
{
    protected $moraCalculator;

    public function __construct(MoraCalculator $moraCalculator)
    {
        $this->moraCalculator = $moraCalculator;
    }

    // $saleId: si viene, el reparto se acota a esa venta (caso específico,
    // §3.3 — el trivial N=1 del mismo algoritmo). El resumen_por_venta y
    // deuda_restante_cliente SIEMPRE consideran TODAS las ventas abiertas
    // del cliente, tocadas o no por este pago — el cajero necesita ver el
    // estado de cuenta completo aunque el pago esté acotado a una venta
    // (§3.2 paso 1, "se muestra siempre... es la vista base del estado de
    // cuenta"). deuda_restante_cliente sigue siendo SOLO capital (§7,
    // "mora acumulada" del estado de cuenta consolidado, es responsabilidad
    // de esa fase, no de acá).
    //
    // $fechaReferencia: fecha contra la que se calculan días de atraso —
    // default hoy si no se pasa. $incluirMora=false lo usa
    // CreditPaymentController::replace() (§3.13): un traspaso de pagos ya
    // existentes no es un evento de cobro nuevo, no debe generar mora
    // fresca sobre la venta nueva.
    public function allocate(
        Client $client,
        float $montoTotal,
        ?int $saleId = null,
        ?Carbon $fechaReferencia = null,
        bool $incluirMora = true
    ): array {
        $fechaReferencia ??= now();

        $todasLasVentasAbiertas = Sale::where('client_id', $client->id)
            ->where('saldo_pendiente', '>', 0)
            ->orderBy('date')
            ->get();

        if ($saleId !== null && !$todasLasVentasAbiertas->contains('id', $saleId)) {
            abort(422, "La venta #{$saleId} no pertenece al cliente #{$client->id} o no tiene saldo_pendiente > 0.");
        }

        $ventasParaReparto = $saleId
            ? $todasLasVentasAbiertas->where('id', $saleId)
            : $todasLasVentasAbiertas;

        $remainingCentavos = (int) round($montoTotal * 100);
        $aplicaciones = [];
        $aplicadoPorVenta = []; // [sale_id => centavos de CAPITAL aplicados en este reparto]
        $moraAplicadaPorVenta = []; // [sale_id => centavos de MORA aplicados en este reparto]

        foreach ($ventasParaReparto as $venta) {
            if ($remainingCentavos <= 0) {
                break;
            }

            if ($venta->credit_type === 'cuotas_fijas') {
                $cuotas = Installment::where('sale_id', $venta->id)
                    ->whereIn('estado', ['pendiente', 'parcial', 'vencida'])
                    ->orderBy('fecha_vencimiento')
                    ->get();

                foreach ($cuotas as $cuota) {
                    if ($remainingCentavos <= 0) {
                        break;
                    }

                    $saldoCuotaCentavos = $this->saldoCentavosInstallment($cuota);
                    if ($saldoCuotaCentavos <= 0) {
                        continue;
                    }

                    $moraCentavos = $incluirMora
                        ? $this->moraCalculator->calcularMoraInstallmentCentavos($cuota, $saldoCuotaCentavos, $fechaReferencia)
                        : 0;

                    [$fila, $capitalCentavos, $moraAplicadaCentavos] = $this->construirFila(
                        $venta->id,
                        $cuota->id,
                        $cuota->numero_cuota,
                        $saldoCuotaCentavos,
                        $moraCentavos,
                        $remainingCentavos
                    );

                    $aplicaciones[] = $fila;
                    $remainingCentavos -= ($capitalCentavos + $moraAplicadaCentavos);
                    $aplicadoPorVenta[$venta->id] = ($aplicadoPorVenta[$venta->id] ?? 0) + $capitalCentavos;
                    $moraAplicadaPorVenta[$venta->id] = ($moraAplicadaPorVenta[$venta->id] ?? 0) + $moraAplicadaCentavos;
                }
            } else {
                // 'libre' o venta 'contado' con deuda vía debt/paid_out (§9)
                // — ambas se cobran igual: contra saldo_pendiente de la venta.
                $saldoVentaCentavos = (int) round((float) $venta->saldo_pendiente * 100);
                if ($saldoVentaCentavos <= 0) {
                    continue;
                }

                $moraCentavos = $incluirMora
                    ? $this->moraCalculator->calcularMoraVentaLibreCentavos($venta, $saldoVentaCentavos, $fechaReferencia)
                    : 0;

                [$fila, $capitalCentavos, $moraAplicadaCentavos] = $this->construirFila(
                    $venta->id,
                    null,
                    null,
                    $saldoVentaCentavos,
                    $moraCentavos,
                    $remainingCentavos
                );

                $aplicaciones[] = $fila;
                $remainingCentavos -= ($capitalCentavos + $moraAplicadaCentavos);
                $aplicadoPorVenta[$venta->id] = ($aplicadoPorVenta[$venta->id] ?? 0) + $capitalCentavos;
                $moraAplicadaPorVenta[$venta->id] = ($moraAplicadaPorVenta[$venta->id] ?? 0) + $moraAplicadaCentavos;
            }
        }

        $resumenPorVenta = $todasLasVentasAbiertas->map(function ($venta) use ($aplicadoPorVenta, $moraAplicadaPorVenta) {
            $saldoAntesCentavos = (int) round((float) $venta->saldo_pendiente * 100);
            $aplicadoCentavos = $aplicadoPorVenta[$venta->id] ?? 0;

            return [
                'sale_id' => $venta->id,
                'saldo_antes' => round($saldoAntesCentavos / 100, 2),
                'monto_aplicado' => round($aplicadoCentavos / 100, 2),
                'saldo_despues' => round(($saldoAntesCentavos - $aplicadoCentavos) / 100, 2),
                'mora_aplicada' => round(($moraAplicadaPorVenta[$venta->id] ?? 0) / 100, 2),
            ];
        })->values()->all();

        $totalAplicadoCentavos = (int) round($montoTotal * 100) - $remainingCentavos;
        $totalMoraAplicadaCentavos = array_sum($moraAplicadaPorVenta);

        return [
            'monto_total' => round($montoTotal, 2),
            'total_aplicado' => round($totalAplicadoCentavos / 100, 2),
            'total_mora_aplicada' => round($totalMoraAplicadaCentavos / 100, 2),
            'monto_no_aplicado' => round($remainingCentavos / 100, 2),
            'deuda_restante_cliente' => round(array_sum(array_column($resumenPorVenta, 'saldo_despues')), 2),
            'aplicaciones' => $aplicaciones,
            'resumen_por_venta' => $resumenPorVenta,
        ];
    }

    // Arma una fila de 'aplicaciones' repartiendo $remainingCentavos entre
    // capital (primero) y mora (después) contra un bucket (cuota o venta
    // libre) de $saldoCapitalCentavos + $moraCentavos de costo total.
    // Devuelve [fila, capitalAplicadoCentavos, moraAplicadaCentavos].
    private function construirFila(
        int $saleId,
        ?int $installmentId,
        ?int $numeroCuota,
        int $saldoCapitalCentavos,
        int $moraCentavos,
        int $remainingCentavos
    ): array {
        $costoTotalCentavos = $saldoCapitalCentavos + $moraCentavos;
        $aplicarTotalCentavos = min($remainingCentavos, $costoTotalCentavos);

        $capitalAplicadoCentavos = min($aplicarTotalCentavos, $saldoCapitalCentavos);
        $moraAplicadaCentavos = $aplicarTotalCentavos - $capitalAplicadoCentavos;

        $fila = [
            'sale_id' => $saleId,
            'installment_id' => $installmentId,
            'numero_cuota' => $numeroCuota,
            'saldo_antes' => round($saldoCapitalCentavos / 100, 2),
            'monto_aplicado' => round($capitalAplicadoCentavos / 100, 2),
            'saldo_despues' => round(($saldoCapitalCentavos - $capitalAplicadoCentavos) / 100, 2),
            'mora_antes' => round($moraCentavos / 100, 2),
            // Mismo nombre que espera CreditPaymentController::store() para
            // 'monto_aplicado' — el preview tiene que poder alimentarse
            // directo a store() sin que el frontend renombre campos, mismo
            // criterio ya usado con monto_aplicado/installment_id.
            'monto_mora_aplicado' => round($moraAplicadaCentavos / 100, 2),
        ];

        return [$fila, $capitalAplicadoCentavos, $moraAplicadaCentavos];
    }

    // Saldo pendiente de UNA cuota, en centavos enteros. SOLO CAPITAL —
    // no incluye mora, nunca la incluyó (Fase 4) y sigue sin incluirla acá
    // a propósito: lo usan §3.5 (redistribución de cuotas) y la
    // recomposición de installment.estado tras anular (§3.6), ninguno de
    // los dos es sobre mora. No hay columna cacheada de "monto pagado" en
    // installments — se deriva sumando las aplicaciones activas. Público
    // porque CreditPaymentController::store() reusa este mismo cálculo
    // para validar cada fila bajo lock (no confía en el saldo_antes que
    // mandó el frontend desde el preview).
    public function saldoCentavosInstallment(Installment $cuota): int
    {
        $programadoCentavos = (int) round((float) $cuota->monto_programado * 100);

        $aplicadoCentavos = (int) round(
            (float) $cuota->paymentApplications()->where('estado', 'activo')->sum('monto_aplicado') * 100
        );

        return $programadoCentavos - $aplicadoCentavos;
    }
}
