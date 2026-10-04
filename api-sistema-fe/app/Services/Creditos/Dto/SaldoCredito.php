<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Fecha;

/**
 * Cifras de cabecera de un crédito a partir de un reparto del motor: las usan el detalle (situación
 * de hoy) y la cotización de un cobro (situación después del pago), así ambas pantallas suman igual.
 * El frontend no hace aritmética de dinero (00 §4). Montos en centavos.
 */
final readonly class SaldoCredito
{
    /**
     * @param list<DeudaCuota> $deudaHoy desglose del exigible: sus líneas suman exactamente lo que
     *                                   devuelve AplicadorPagos::montoExigible() a la misma fecha
     */
    public function __construct(
        public int $capital,
        public int $interes,
        public int $cargos,
        public int $mora,
        public int $totalPagado,
        public int $cuotasPagadas,
        public int $cuotasVencidas,
        public ?ProximaCuota $proxima,
        public array $deudaHoy,
    ) {
    }

    public static function calcular(EstadoCredito $estado, ResultadoAplicacion $situacion, Fecha $hoy): self
    {
        $capital = $interes = $cargos = $totalPagado = $cuotasPagadas = $cuotasVencidas = 0;
        $proxima = null;
        $deudaHoy = [];
        foreach ($estado->cuotas as $cuota) {
            $saldada = $situacion->cuota($cuota->numero);
            $mora = $situacion->mora($cuota->numero);
            $pendienteCapital = $cuota->montoCapital - $saldada->capitalPagado;
            $pendienteInteres = $cuota->montoInteres - $saldada->interesPagado - $saldada->interesCondonado;
            $pendienteCargo = $cuota->cargoMonto - $saldada->cargoPagado;
            $pendienteBase = $pendienteCapital + $pendienteInteres;
            $capital += $pendienteCapital;
            $interes += $pendienteInteres;
            $cargos += $pendienteCargo;
            $totalPagado += $saldada->capitalPagado + $saldada->interesPagado + $saldada->cargoPagado + $saldada->moraPagada;

            // Mismo criterio que montoExigible(): vencidas completas, la próxima por vencer,
            // cargos de cualquier cuota y la mora (una cuota pagada puede seguir debiendo mora).
            $vencida = $cuota->fechaVencimiento->esAnteriorA($hoy);
            $esProxima = ! $vencida && $proxima === null && $pendienteBase > 0;
            $base = $vencida || $esProxima ? $pendienteBase : 0;
            if ($base + $pendienteCargo + $mora->moraPendiente > 0) {
                $deudaHoy[] = new DeudaCuota($cuota->numero, $cuota->fechaVencimiento, $base + $pendienteCargo, $mora->moraPendiente, $mora->diasAtraso, $mora->topeAlcanzado, $vencida);
            }

            if ($saldada->estado === EstadoCuota::Pagada) {
                $cuotasPagadas++;
            } elseif ($vencida) {
                $cuotasVencidas++;
            }
            if ($esProxima) {
                $proxima = new ProximaCuota($cuota->numero, $cuota->fechaVencimiento, $pendienteBase + $pendienteCargo);
            }
        }

        return new self($capital, $interes, $cargos, $situacion->moraPendienteTotal(), $totalPagado, $cuotasPagadas, $cuotasVencidas, $proxima, $deudaHoy);
    }

    /** Capital + interés + cargos + mora pendientes: lo que falta para cancelar según cronograma. */
    public function porPagar(): int
    {
        return $this->capital + $this->interes + $this->cargos + $this->mora;
    }
}
