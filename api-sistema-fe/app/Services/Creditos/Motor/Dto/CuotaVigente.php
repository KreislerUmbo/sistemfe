<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Cuota de la versión vigente del cronograma, tal como está en BD. Montos en centavos. */
final readonly class CuotaVigente
{
    /**
     * @param list<MoraCongelada> $morasCongeladas congeladas con fecha (reprogramaciones reales)
     */
    public function __construct(
        public int $numero,
        public Fecha $fechaInicioPeriodo,
        public Fecha $fechaVencimiento,
        /** El divisor de la mora usa el período original aunque la cuota se reprograme (1.18, 12.4). */
        public Fecha $fechaVencimientoOriginal,
        public int $montoCapital,
        public int $montoInteres,
        public int $cargoMonto = 0,
        /** Mora congelada visible en cualquier fecha (sin fecha de reprogramación). */
        public int $moraCongelada = 0,
        /** Suma de condonaciones vigentes: vive fuera de las aplicaciones y sobrevive a los recálculos (1.6). */
        public int $moraCondonada = 0,
        public array $morasCongeladas = [],
    ) {
    }

    public static function desdeProgramada(CuotaProgramada $cuota): self
    {
        return new self(
            $cuota->numero,
            $cuota->fechaInicioPeriodo,
            $cuota->fechaVencimiento,
            $cuota->fechaVencimiento,
            $cuota->montoCapital,
            $cuota->montoInteres,
        );
    }

    public function conFechaVencimiento(Fecha $fecha): self
    {
        return new self(
            $this->numero, $this->fechaInicioPeriodo, $fecha, $this->fechaVencimientoOriginal,
            $this->montoCapital, $this->montoInteres, $this->cargoMonto, $this->moraCongelada, $this->moraCondonada,
            $this->morasCongeladas,
        );
    }

    /** Mora congelada que existe a esa fecha: la fechada cuenta desde su reprogramación. */
    public function moraCongeladaA(Fecha $fecha): int
    {
        $total = $this->moraCongelada;
        foreach ($this->morasCongeladas as $congelada) {
            if (! $fecha->esAnteriorA($congelada->desde)) {
                $total += $congelada->monto;
            }
        }

        return $total;
    }

    /** Capital + interés: base sobre la que corre la mora (la mora no corre sobre el cargo, 1.18). */
    public function montoBaseMora(): int
    {
        return $this->montoCapital + $this->montoInteres;
    }
}
