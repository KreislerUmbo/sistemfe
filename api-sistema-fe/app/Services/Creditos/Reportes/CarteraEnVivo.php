<?php

declare(strict_types=1);

namespace App\Services\Creditos\Reportes;

use App\Enums\Creditos\CreditoEstado;
use App\Models\Creditos\Credito;
use App\Services\Creditos\CargadorCredito;
use App\Services\Creditos\Dto\SaldoCredito;
use App\Services\Creditos\Motor\AplicadorPagos;
use App\Services\Creditos\Motor\Dto\MoraCuota;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Database\Eloquent\Builder;

/**
 * Situación en vivo de muchos créditos a la vez (04d): carga en bloque (CargadorCredito::
 * cargarVarios) y el mismo reparto del motor que usa el detalle (AplicadorPagos + SaldoCredito).
 * Una sola fuente de verdad: un reporte y el detalle de un crédito nunca suman distinto.
 */
class CarteraEnVivo
{
    /** Créditos que tienen saldo: los finalizados y anulados no entran a cartera. */
    public const ESTADOS = [CreditoEstado::Activo, CreditoEstado::Castigado];
    private const BLOQUE = 200;

    public function __construct(
        private readonly CargadorCredito $cargador,
        private readonly AplicadorPagos $aplicador,
    ) {
    }

    /**
     * @param Builder<Credito> $creditos consulta ya filtrada (alcance de cartera, estado…)
     * @return list<SituacionCartera>
     */
    public function calcular(Builder $creditos, Fecha $corte): array
    {
        $situaciones = [];
        $creditos->with('cliente')->orderBy('id')->chunk(self::BLOQUE, function ($bloque) use ($corte, &$situaciones): void {
            foreach ($this->cargador->cargarVarios($bloque) as $id => $carga) {
                $credito = $bloque->firstWhere('id', $id);
                $reparto = $this->aplicador->aplicar($carga->estado, $carga->pagos, $corte);
                $saldo = SaldoCredito::calcular($carga->estado, $reparto, $corte);
                $situaciones[] = new SituacionCartera(
                    $credito,
                    $saldo->capital,
                    $saldo->interes,
                    $saldo->cargos,
                    $saldo->mora,
                    (int) max(array_map(static fn (MoraCuota $m): int => $m->diasAtraso, $reparto->moraAFecha) ?: [0]),
                    $saldo->cuotasPagadas,
                    count($carga->estado->cuotas),
                    $saldo->proxima,
                );
            }
        });

        return $situaciones;
    }
}
