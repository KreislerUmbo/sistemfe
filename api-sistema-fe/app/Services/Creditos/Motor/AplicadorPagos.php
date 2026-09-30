<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor;

use App\Services\Creditos\Motor\Dto\Abono;
use App\Services\Creditos\Motor\Dto\Aplicacion;
use App\Services\Creditos\Motor\Dto\CuotaSaldada;
use App\Services\Creditos\Motor\Dto\CuotaVigente;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;
use App\Services\Creditos\Motor\Dto\ResultadoPago;
use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Enums\OrigenPago;
use App\Services\Creditos\Motor\Excepciones\PagoExcedeDeuda;

/**
 * Reparte TODOS los pagos válidos de un crédito desde cero, en orden (fecha_pago, secuencia).
 * Es determinista: el mismo conjunto de pagos da siempre el mismo reparto, por eso sirve para el
 * cobro, el preview, la anulación (1.9), el pago retroactivo (1.22) y la migración (1.20).
 *
 * Orden de un pago normal (1.7, 12.6):
 *   1. cuotas vencidas (antigua → nueva): interés → capital → cargo
 *   2. mora (antigua → nueva)
 *   3. próxima cuota por vencer + cargos pendientes (sigue siendo exigible, no excedente)
 *   4. excedente: con destino `adelanto` → cuotas siguientes; si no, queda fuera del crédito
 *
 * Estado interno por cuota (arrays privados de esta clase, nunca expuestos): acumulados pagados,
 * abonos con fecha y fecha en que quedó totalmente pagada.
 */
final class AplicadorPagos
{
    private const ORDEN_CUOTA = [ConceptoAplicacion::Interes, ConceptoAplicacion::Capital, ConceptoAplicacion::Cargo];

    /** @var array<int, array<string, int>> número de cuota => concepto => pagado */
    private array $pagado = [];
    /** @var array<int, int> */
    private array $interesCondonado = [];
    /** @var array<int, list<Abono>> */
    private array $abonos = [];
    /** @var array<int, Fecha|null> */
    private array $fechaPago = [];
    /** @var list<Aplicacion> */
    private array $aplicaciones = [];
    private bool $cerrado = false;

    public function __construct(
        private readonly CalculadoraMora $mora,
        private readonly CalculadoraLiquidacion $liquidacion,
    ) {
    }

    /** @param list<PagoAAplicar> $pagos pagos válidos (los anulados no se envían) */
    public function aplicar(EstadoCredito $estado, array $pagos, Fecha $fechaReferencia): ResultadoAplicacion
    {
        $this->reiniciar($estado);
        $pagos = $this->ordenar($pagos);

        $resultados = [];
        $cierresInsuficientes = [];
        foreach ($pagos as $pago) {
            if ($pago->fechaPago->esPosteriorA($fechaReferencia)) {
                throw new \InvalidArgumentException("El pago {$pago->referencia} es posterior a la fecha de referencia.");
            }
            [$resultado, $insuficiente] = $this->aplicarPago($estado, $pago);
            $resultados[] = $resultado;
            if ($insuficiente) {
                $cierresInsuficientes[] = $pago->referencia;
            }
        }

        return $this->foto($estado, $fechaReferencia, $resultados, $cierresInsuficientes);
    }

    /**
     * Lo que se debe a la fecha (12.6): vencidas + mora (incl. congelada) + cargos pendientes +
     * la próxima cuota por vencer. Lo que lo supere es excedente.
     *
     * @param list<PagoAAplicar> $pagos
     */
    public function montoExigible(EstadoCredito $estado, array $pagos, Fecha $fecha): int
    {
        $resultado = $this->aplicar($estado, $pagos, $fecha);
        if ($resultado->finalizado) {
            return 0;
        }

        $exigible = $resultado->moraPendienteTotal();
        $proximaIncluida = false;
        foreach ($estado->cuotas as $cuota) {
            $saldada = $resultado->cuota($cuota->numero);
            $pendienteBase = $cuota->montoBaseMora() - $saldada->capitalPagado - $saldada->interesPagado - $saldada->interesCondonado;
            $exigible += $cuota->cargoMonto - $saldada->cargoPagado;

            if ($cuota->fechaVencimiento->esAnteriorA($fecha)) {
                $exigible += $pendienteBase;
            } elseif (! $proximaIncluida && $pendienteBase > 0) {
                $exigible += $pendienteBase;
                $proximaIncluida = true;
            }
        }

        return $exigible;
    }

    /** @return array{ResultadoPago, bool} resultado y si era un cierre que ya no alcanza (12.8) */
    private function aplicarPago(EstadoCredito $estado, PagoAAplicar $pago): array
    {
        $fecha = $pago->fechaPago;
        $esRecupero = $this->esRecupero($estado, $fecha);

        if ($this->cerrado) {
            return [new ResultadoPago($pago->referencia, 0, $pago->monto, false, $esRecupero), false];
        }

        $intentaCierre = $pago->origen->esCierreObligatorio()
            || ($pago->origen === OrigenPago::VentaPrenda && $pago->esCierre !== false);
        if ($intentaCierre) {
            $liquidacion = $this->liquidacionA($estado, $fecha);
            if ($pago->monto >= $liquidacion->montoLiquidacion) {
                $this->aplicarLiquidacion($liquidacion, $pago);

                return [new ResultadoPago(
                    $pago->referencia, $liquidacion->montoLiquidacion, $pago->monto - $liquidacion->montoLiquidacion, true, $esRecupero,
                ), false];
            }
        }

        // Venta de prenda que no cubre la liquidación = pago normal (1.11). Un cierre que ya no
        // alcanza se reparte como pago normal y se marca para que la Fase 3 bloquee la anulación (12.8).
        $insuficiente = $intentaCierre
            && ($pago->origen->esCierreObligatorio() || $pago->esCierre === true);
        $aplicado = $this->aplicarNormal($estado, $pago);

        return [new ResultadoPago($pago->referencia, $aplicado, $pago->monto - $aplicado, false, $esRecupero), $insuficiente];
    }

    /**
     * 12.11: pago hecho durante un castigo vigente. Derivado: al revertir el castigo, sus pagos
     * dejan de ser recupero.
     */
    private function esRecupero(EstadoCredito $estado, Fecha $fecha): bool
    {
        foreach ($estado->reglasMora->periodosCastigo as $periodo) {
            if ($periodo->estaVigente() && ! $fecha->esAnteriorA($periodo->desde)) {
                return true;
            }
        }

        return false;
    }

    /** @return int monto aplicado al crédito */
    private function aplicarNormal(EstadoCredito $estado, PagoAAplicar $pago): int
    {
        $fecha = $pago->fechaPago;
        // Se cotiza antes de tocar nada: el tope de 12.5 es la liquidación previa al pago.
        $liquidacionPrevia = $pago->destinoExcedente === DestinoExcedente::Adelanto
            ? $this->liquidacionA($estado, $fecha)
            : null;
        $disponible = $pago->monto;

        // 1. Cuotas vencidas.
        foreach ($estado->cuotas as $cuota) {
            if ($cuota->fechaVencimiento->esAnteriorA($fecha)) {
                $this->pagarConceptos($cuota, self::ORDEN_CUOTA, $disponible, $pago);
            }
        }

        // 2. Mora, calculada a la fecha del pago con los abonos anteriores.
        foreach ($this->mora->deCredito($estado, $this->abonos, $fecha) as $mora) {
            $monto = min($disponible, $mora->moraPendiente);
            $this->registrar($mora->numeroCuota, ConceptoAplicacion::Mora, $monto, $pago);
            $disponible -= $monto;
        }

        // 3. Próxima cuota por vencer y cargos pendientes (12.6).
        $proxima = $this->proximaPorVencer($estado, $fecha);
        if ($proxima !== null) {
            $this->pagarConceptos($proxima, self::ORDEN_CUOTA, $disponible, $pago);
        }
        foreach ($estado->cuotas as $cuota) {
            $this->pagarConceptos($cuota, [ConceptoAplicacion::Cargo], $disponible, $pago);
        }

        // 4. Excedente.
        if ($liquidacionPrevia !== null && $disponible > 0) {
            $adelantable = min($disponible, $this->pendienteTotal($estado));
            $aplicable = $pago->monto - $disponible + $adelantable;
            if ($adelantable > 0 && $aplicable > $liquidacionPrevia->montoLiquidacion) {
                throw new PagoExcedeDeuda($aplicable, $liquidacionPrevia->montoLiquidacion);
            }
            foreach ($estado->cuotas as $cuota) {
                $this->pagarConceptos($cuota, self::ORDEN_CUOTA, $disponible, $pago);
            }
        }

        $this->marcarPagadas($estado, $fecha);

        return $pago->monto - $disponible;
    }

    /** 1.5: todas las cuotas quedan pagadas; el interés no cobrado se condona. */
    private function aplicarLiquidacion(Liquidacion $liquidacion, PagoAAplicar $pago): void
    {
        foreach ($liquidacion->reparto as $r) {
            $this->registrar($r->numeroCuota, ConceptoAplicacion::Interes, $r->interes, $pago);
            $this->registrar($r->numeroCuota, ConceptoAplicacion::Capital, $r->capital, $pago);
            $this->registrar($r->numeroCuota, ConceptoAplicacion::Cargo, $r->cargo, $pago);
            $this->registrar($r->numeroCuota, ConceptoAplicacion::Mora, $r->mora, $pago);
            $this->interesCondonado[$r->numeroCuota] += $r->interesCondonado;
            $this->fechaPago[$r->numeroCuota] ??= $pago->fechaPago;
        }
        $this->cerrado = true;
    }

    /** @param list<ConceptoAplicacion> $conceptos */
    private function pagarConceptos(CuotaVigente $cuota, array $conceptos, int &$disponible, PagoAAplicar $pago): void
    {
        foreach ($conceptos as $concepto) {
            $monto = min($disponible, $this->pendiente($cuota, $concepto));
            $this->registrar($cuota->numero, $concepto, $monto, $pago);
            $disponible -= $monto;
        }
    }

    private function registrar(int $numeroCuota, ConceptoAplicacion $concepto, int $monto, PagoAAplicar $pago): void
    {
        if ($monto <= 0) {
            return;
        }
        $this->pagado[$numeroCuota][$concepto->value] += $monto;
        $this->abonos[$numeroCuota][] = new Abono($pago->fechaPago, $concepto, $monto);
        $this->aplicaciones[] = new Aplicacion($pago->referencia, $numeroCuota, $concepto, $monto);
    }

    private function pendiente(CuotaVigente $cuota, ConceptoAplicacion $concepto): int
    {
        $pagado = $this->pagado[$cuota->numero][$concepto->value];

        return match ($concepto) {
            ConceptoAplicacion::Interes => $cuota->montoInteres - $pagado - $this->interesCondonado[$cuota->numero],
            ConceptoAplicacion::Capital => $cuota->montoCapital - $pagado,
            ConceptoAplicacion::Cargo => $cuota->cargoMonto - $pagado,
            ConceptoAplicacion::Mora => 0,
        };
    }

    private function pendienteTotal(EstadoCredito $estado): int
    {
        $total = 0;
        foreach ($estado->cuotas as $cuota) {
            foreach (self::ORDEN_CUOTA as $concepto) {
                $total += $this->pendiente($cuota, $concepto);
            }
        }

        return $total;
    }

    /** Primera cuota que vence hoy o después y aún tiene capital o interés pendiente (12.11). */
    private function proximaPorVencer(EstadoCredito $estado, Fecha $fecha): ?CuotaVigente
    {
        foreach ($estado->cuotas as $cuota) {
            if (! $cuota->fechaVencimiento->esAnteriorA($fecha)
                && $this->pendiente($cuota, ConceptoAplicacion::Interes) + $this->pendiente($cuota, ConceptoAplicacion::Capital) > 0) {
                return $cuota;
            }
        }

        return null;
    }

    private function marcarPagadas(EstadoCredito $estado, Fecha $fecha): void
    {
        foreach ($estado->cuotas as $cuota) {
            if ($this->fechaPago[$cuota->numero] === null && $this->estaSaldada($cuota)) {
                $this->fechaPago[$cuota->numero] = $fecha;
            }
        }
    }

    private function estaSaldada(CuotaVigente $cuota): bool
    {
        foreach (self::ORDEN_CUOTA as $concepto) {
            if ($this->pendiente($cuota, $concepto) > 0) {
                return false;
            }
        }

        return true;
    }

    private function liquidacionA(EstadoCredito $estado, Fecha $fecha): Liquidacion
    {
        return $this->liquidacion->calcular($estado, $this->foto($estado, $fecha, [], []), $fecha);
    }

    /**
     * @param list<ResultadoPago> $pagos
     * @param list<int|string> $cierresInsuficientes
     */
    private function foto(EstadoCredito $estado, Fecha $fecha, array $pagos, array $cierresInsuficientes): ResultadoAplicacion
    {
        $cuotas = [];
        $todasPagadas = true;
        foreach ($estado->cuotas as $cuota) {
            $n = $cuota->numero;
            $fechaPago = $this->fechaPago[$n];
            $todasPagadas = $todasPagadas && $fechaPago !== null;
            $cuotas[] = new CuotaSaldada(
                $n,
                $this->pagado[$n][ConceptoAplicacion::Capital->value],
                $this->pagado[$n][ConceptoAplicacion::Interes->value],
                $this->pagado[$n][ConceptoAplicacion::Cargo->value],
                $this->pagado[$n][ConceptoAplicacion::Mora->value],
                $this->interesCondonado[$n],
                $fechaPago === null ? EstadoCuota::Pendiente : EstadoCuota::Pagada,
                $fechaPago,
                $fechaPago === null ? null : max(0, $cuota->fechaVencimiento->diasHasta($fechaPago)),
            );
        }

        $moras = $this->mora->deCredito($estado, $this->abonos, $fecha);
        $resultado = new ResultadoAplicacion($fecha, $this->aplicaciones, $pagos, $cuotas, $moras, false, $cierresInsuficientes);

        // 1.7: finaliza con todas las cuotas pagadas y sin mora pendiente (pagada o condonada).
        $finalizado = $this->cerrado || ($todasPagadas && $resultado->moraPendienteTotal() === 0);

        return new ResultadoAplicacion($fecha, $this->aplicaciones, $pagos, $cuotas, $moras, $finalizado, $cierresInsuficientes);
    }

    private function reiniciar(EstadoCredito $estado): void
    {
        $this->pagado = [];
        $this->interesCondonado = [];
        $this->abonos = [];
        $this->fechaPago = [];
        $this->aplicaciones = [];
        $this->cerrado = false;
        foreach ($estado->cuotas as $cuota) {
            foreach (ConceptoAplicacion::cases() as $concepto) {
                $this->pagado[$cuota->numero][$concepto->value] = 0;
            }
            $this->interesCondonado[$cuota->numero] = 0;
            $this->abonos[$cuota->numero] = [];
            $this->fechaPago[$cuota->numero] = null;
        }
    }

    /**
     * @param list<PagoAAplicar> $pagos
     * @return list<PagoAAplicar>
     */
    private function ordenar(array $pagos): array
    {
        usort(
            $pagos,
            static fn (PagoAAplicar $a, PagoAAplicar $b): int => $a->fechaPago->comparar($b->fechaPago)
                ?: $a->secuencia <=> $b->secuencia,
        );

        return $pagos;
    }
}
