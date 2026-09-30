<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\RepartoLiquidacionCuota;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;

/**
 * Liquidación anticipada (plan 1.5, 12.7):
 *   interes_final     = min(max(devengado, capital × tasa_minimo), interés total)
 *   monto_liquidacion = capital pendiente + interes_final − interés cobrado + mora pendiente + cargos pendientes
 * Montos en centavos.
 */
final class CalculadoraLiquidacion
{
    /** La mora pendiente llega ya calculada en el ResultadoAplicacion (misma fecha). */
    public function __construct(private readonly CalculadoraInteres $interes)
    {
    }

    /** $pagado debe estar calculado a $fechaReferencia: la mora y el proporcional cambian cada día. */
    public function calcular(EstadoCredito $estado, ResultadoAplicacion $pagado, Fecha $fechaReferencia): Liquidacion
    {
        if (! $pagado->fechaReferencia->esIgualA($fechaReferencia)) {
            throw new \InvalidArgumentException('El reparto de pagos debe estar calculado a la misma fecha de la liquidación.');
        }

        $paso = $estado->pasoRedondeo;
        $capitalPendiente = 0;
        $interesCobrado = 0;
        $interesDevengado = 0;
        $cargoPendiente = 0;
        foreach ($estado->cuotas as $cuota) {
            $saldada = $pagado->cuota($cuota->numero);
            $capitalPendiente += $cuota->montoCapital - $saldada->capitalPagado;
            $interesCobrado += $saldada->interesPagado;
            $cargoPendiente += $cuota->cargoMonto - $saldada->cargoPagado;
            $interesDevengado += $this->interesDevengadoDeCuota(
                $cuota->montoInteres, $cuota->fechaInicioPeriodo, $cuota->fechaVencimiento, $fechaReferencia,
            );
        }

        $interesMinimo = $this->interes->montoPorTasa($estado->montoCapital, $estado->tasaInteresMinimo, $paso);
        $interesFinal = min(
            max(Redondeo::dividir($interesDevengado, 1, $paso), $interesMinimo),
            $estado->interesTotal,
        );
        // Lo ya cobrado de más por adelantar cuotas no se devuelve (1.5: adelantar ≠ liquidar).
        $interesACobrar = max(0, $interesFinal - $interesCobrado);
        $moraPendiente = $pagado->moraPendienteTotal();

        $reparto = $this->repartir($estado, $pagado, $interesACobrar);
        $interesCondonado = array_sum(array_map(static fn (RepartoLiquidacionCuota $r): int => $r->interesCondonado, $reparto));

        return new Liquidacion(
            $fechaReferencia,
            $capitalPendiente,
            $interesDevengado,
            $interesMinimo,
            $interesFinal,
            $interesCobrado,
            $interesACobrar,
            $interesCondonado,
            $cargoPendiente,
            $moraPendiente,
            $capitalPendiente + $interesACobrar + $moraPendiente + $cargoPendiente,
            $reparto,
        );
    }

    /** Vencida (o vence hoy): interés completo; en curso: proporcional a días reales; futura: 0. */
    private function interesDevengadoDeCuota(int $montoInteres, Fecha $inicio, Fecha $vencimiento, Fecha $fecha): int
    {
        if (! $fecha->esAnteriorA($vencimiento)) {
            return $montoInteres;
        }
        if (! $fecha->esPosteriorA($inicio)) {
            return 0;
        }

        return $this->interes->proporcional(
            $montoInteres, $inicio->diasHasta($fecha), $inicio->diasHasta($vencimiento), Redondeo::PASO_CENTIMO,
        );
    }

    /**
     * 1.5: capital a todas las cuotas; el interés a cobrar, en orden hasta agotarse; el resto del
     * interés de cada cuota se condona. Todas las cuotas quedan pagadas.
     *
     * @return list<RepartoLiquidacionCuota>
     */
    private function repartir(EstadoCredito $estado, ResultadoAplicacion $pagado, int $interesACobrar): array
    {
        $reparto = [];
        foreach ($estado->cuotas as $cuota) {
            $saldada = $pagado->cuota($cuota->numero);
            $capital = $cuota->montoCapital - $saldada->capitalPagado;
            $interesPendiente = $cuota->montoInteres - $saldada->interesPagado - $saldada->interesCondonado;
            $interes = min($interesPendiente, $interesACobrar);
            $interesACobrar -= $interes;
            $cargo = $cuota->cargoMonto - $saldada->cargoPagado;
            $mora = $pagado->mora($cuota->numero)->moraPendiente;

            if ($capital + $interesPendiente + $cargo + $mora === 0) {
                continue;
            }
            $reparto[] = new RepartoLiquidacionCuota(
                $cuota->numero, $capital, $interes, $interesPendiente - $interes, $cargo, $mora,
            );
        }

        return $reparto;
    }
}
