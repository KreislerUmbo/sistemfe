<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Abono;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\MoraCuota;
use App\Services\Creditos\Motor\Dto\ReglasMora;
use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;

/**
 * Mora ("interés moratorio") calculada al consultar, nunca guardada día a día (plan 1.6, 1.19, 12.4).
 * Montos en centavos.
 */
final class CalculadoraMora
{
    public function __construct(private readonly CalculadoraInteres $interes)
    {
    }

    /**
     * Mora de una cuota a la fecha, por tramos: cada abono a capital/interés baja el saldo desde el
     * día siguiente (el día del pago todavía corre sobre el saldo anterior).
     * mora = Σ saldo_tramo × días_tramo ÷ días calendario del período original, redondeada una vez.
     *
     * @param list<Abono> $abonos abonos de ESTA cuota (cualquier concepto)
     */
    public function deCuota(
        CuotaVigente $cuota,
        array $abonos,
        Fecha $fechaReferencia,
        ReglasMora $reglas,
        CalendarioLaborable $calendario,
    ): MoraCuota {
        $abonos = $this->ordenar($abonos);
        $moraPagada = $this->sumar($abonos, ConceptoAplicacion::Mora);

        // 1.6: la mora cuenta desde el fin de la gracia; 1.19: se congela a la fecha de castigo.
        $inicio = $cuota->fechaVencimiento->sumarDias($reglas->diasGracia);
        $fin = $reglas->fechaCongelamiento === null
            ? $fechaReferencia
            : Fecha::menor($fechaReferencia, $reglas->fechaCongelamiento);
        if ($reglas->topeTipo === TopeMoraTipo::DiasMaximos && $reglas->topeValor !== null) {
            $fin = Fecha::menor($fin, $cuota->fechaVencimiento->sumarDias($reglas->topeValor));
        }

        $numerador = $this->sumaSaldoPorDias($cuota, $abonos, $inicio, $fin, $reglas, $calendario);
        $diasPeriodo = max(1, $cuota->fechaInicioPeriodo->diasHasta($cuota->fechaVencimientoOriginal));
        $generada = Redondeo::dividir($numerador, $diasPeriodo, $reglas->pasoRedondeo);

        $topeAlcanzado = false;
        if ($reglas->topeTipo === TopeMoraTipo::PorcentajeCuota && $reglas->topeValor !== null) {
            $tope = $this->interes->montoPorPorcentaje($cuota->montoBaseMora(), $reglas->topeValor, $reglas->pasoRedondeo);
            // La mora congelada cuenta dentro del tope de la cuota.
            $maximo = max(0, $tope - $cuota->moraCongelada);
            if ($numerador > 0 && $generada >= $maximo) {
                $generada = $maximo;
                $topeAlcanzado = true;
            }
        }
        if ($reglas->topeTipo === TopeMoraTipo::DiasMaximos && $reglas->topeValor !== null) {
            $topeAlcanzado = $this->saldoBase($cuota, $abonos, $fechaReferencia) > 0
                && $cuota->fechaVencimiento->diasHasta($fechaReferencia) > $reglas->topeValor;
        }

        return $this->armar($cuota, $abonos, $fechaReferencia, $generada, $moraPagada, $topeAlcanzado);
    }

    /**
     * Mora de todas las cuotas; aplica además el tope porcentaje_capital, que es del crédito entero
     * y se reparte de la cuota más antigua a la más nueva.
     *
     * @param array<int, list<Abono>> $abonosPorCuota número de cuota => abonos
     * @return list<MoraCuota>
     */
    public function deCredito(EstadoCredito $estado, array $abonosPorCuota, Fecha $fechaReferencia): array
    {
        $calendario = new CalendarioLaborable($estado->calendario);
        $moras = [];
        foreach ($estado->cuotas as $cuota) {
            $moras[] = $this->deCuota($cuota, $abonosPorCuota[$cuota->numero] ?? [], $fechaReferencia, $estado->reglasMora, $calendario);
        }

        $reglas = $estado->reglasMora;
        if ($reglas->topeTipo !== TopeMoraTipo::PorcentajeCapital || $reglas->topeValor === null) {
            return $moras;
        }

        $disponible = $this->interes->montoPorPorcentaje($estado->montoCapital, $reglas->topeValor, $reglas->pasoRedondeo);
        foreach ($estado->cuotas as $cuota) {
            $disponible -= $cuota->moraCongelada;
        }

        $resultado = [];
        foreach ($moras as $i => $mora) {
            $permitida = min($mora->moraGenerada, max(0, $disponible));
            $disponible -= $permitida;
            $cuota = $estado->cuotas[$i];
            $resultado[] = $this->armar(
                $cuota, $this->ordenar($abonosPorCuota[$cuota->numero] ?? []), $fechaReferencia,
                $permitida, $mora->moraPagada, $permitida < $mora->moraGenerada,
            );
        }

        return $resultado;
    }

    /**
     * Mayor atraso vigente del crédito, en días calendario (escalamiento 1.19, prendas 1.11).
     *
     * @param array<int, list<Abono>> $abonosPorCuota
     */
    public function diasAtrasoCredito(EstadoCredito $estado, array $abonosPorCuota, Fecha $fechaReferencia): int
    {
        $maximo = 0;
        foreach ($estado->cuotas as $cuota) {
            $abonos = $abonosPorCuota[$cuota->numero] ?? [];
            if ($this->saldoBase($cuota, $abonos, $fechaReferencia) > 0) {
                $maximo = max($maximo, $cuota->fechaVencimiento->diasHasta($fechaReferencia));
            }
        }

        return $maximo;
    }

    /** @param list<Abono> $abonos ordenados */
    private function sumaSaldoPorDias(
        CuotaVigente $cuota,
        array $abonos,
        Fecha $inicio,
        Fecha $fin,
        ReglasMora $reglas,
        CalendarioLaborable $calendario,
    ): int {
        if (! $fin->esPosteriorA($inicio)) {
            return 0;
        }

        $saldo = $cuota->montoBaseMora();
        $cursor = $inicio;
        $numerador = 0;
        foreach ($abonos as $abono) {
            if (! $this->reduceSaldo($abono)) {
                continue;
            }
            if ($abono->fecha->esPosteriorA($cursor)) {
                $hasta = Fecha::menor($abono->fecha, $fin);
                $numerador += Redondeo::multiplicar($saldo, $calendario->diasEntre($cursor, $hasta, $reglas->cuentaNoLaborables));
                $cursor = $hasta;
            }
            if (! $cursor->esAnteriorA($fin)) {
                return $numerador;
            }
            $saldo -= $abono->monto;
        }

        return $numerador + Redondeo::multiplicar(max(0, $saldo), $calendario->diasEntre($cursor, $fin, $reglas->cuentaNoLaborables));
    }

    /** @param list<Abono> $abonos */
    private function armar(
        CuotaVigente $cuota,
        array $abonos,
        Fecha $fechaReferencia,
        int $generada,
        int $moraPagada,
        bool $topeAlcanzado,
    ): MoraCuota {
        $diasAtraso = $this->saldoBase($cuota, $abonos, $fechaReferencia) > 0
            ? max(0, $cuota->fechaVencimiento->diasHasta($fechaReferencia))
            : 0;

        return new MoraCuota(
            $cuota->numero,
            $diasAtraso,
            $generada,
            $cuota->moraCongelada,
            $moraPagada,
            $cuota->moraCondonada,
            max(0, $generada + $cuota->moraCongelada - $moraPagada - $cuota->moraCondonada),
            $topeAlcanzado,
        );
    }

    /** Capital + interés impagos de la cuota con los abonos hasta $fecha inclusive. */
    private function saldoBase(CuotaVigente $cuota, array $abonos, Fecha $fecha): int
    {
        $saldo = $cuota->montoBaseMora();
        foreach ($abonos as $abono) {
            if ($this->reduceSaldo($abono) && ! $abono->fecha->esPosteriorA($fecha)) {
                $saldo -= $abono->monto;
            }
        }

        return $saldo;
    }

    /** La mora corre sobre capital + interés; ni el cargo ni la propia mora la reducen (1.18). */
    private function reduceSaldo(Abono $abono): bool
    {
        return $abono->concepto === ConceptoAplicacion::Capital || $abono->concepto === ConceptoAplicacion::Interes;
    }

    /** @param list<Abono> $abonos */
    private function sumar(array $abonos, ConceptoAplicacion $concepto): int
    {
        $total = 0;
        foreach ($abonos as $abono) {
            if ($abono->concepto === $concepto) {
                $total += $abono->monto;
            }
        }

        return $total;
    }

    /**
     * @param list<Abono> $abonos
     * @return list<Abono>
     */
    private function ordenar(array $abonos): array
    {
        usort($abonos, static fn (Abono $a, Abono $b): int => $a->fecha->comparar($b->fecha));

        return $abonos;
    }
}
