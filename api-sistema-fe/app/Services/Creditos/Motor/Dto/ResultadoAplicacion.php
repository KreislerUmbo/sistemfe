<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Resultado determinista de repartir todos los pagos válidos de un crédito. */
final readonly class ResultadoAplicacion
{
    /**
     * @param list<Aplicacion> $aplicaciones
     * @param list<ResultadoPago> $pagos
     * @param list<CuotaSaldada> $cuotas
     * @param list<MoraCuota> $moraAFecha mora de cada cuota a $fechaReferencia
     * @param list<int|string> $cierresInsuficientes pagos de cierre que ya no alcanzan (12.8)
     */
    public function __construct(
        public Fecha $fechaReferencia,
        public array $aplicaciones,
        public array $pagos,
        public array $cuotas,
        public array $moraAFecha,
        public bool $finalizado,
        public array $cierresInsuficientes = [],
    ) {
    }

    public function cuota(int $numero): CuotaSaldada
    {
        foreach ($this->cuotas as $cuota) {
            if ($cuota->numero === $numero) {
                return $cuota;
            }
        }
        throw new \OutOfBoundsException("Cuota {$numero} inexistente.");
    }

    public function mora(int $numeroCuota): MoraCuota
    {
        foreach ($this->moraAFecha as $mora) {
            if ($mora->numeroCuota === $numeroCuota) {
                return $mora;
            }
        }
        throw new \OutOfBoundsException("Cuota {$numeroCuota} inexistente.");
    }

    public function pago(int|string $referencia): ResultadoPago
    {
        foreach ($this->pagos as $pago) {
            if ($pago->referenciaPago === $referencia) {
                return $pago;
            }
        }
        throw new \OutOfBoundsException("Pago {$referencia} inexistente.");
    }

    public function moraPendienteTotal(): int
    {
        return array_sum(array_map(static fn (MoraCuota $m): int => $m->moraPendiente, $this->moraAFecha));
    }
}
