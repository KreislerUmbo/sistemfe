<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Cuota de la versión vigente del cronograma, tal como está en BD. Montos en centavos. */
final readonly class CuotaVigente
{
    public function __construct(
        public int $numero,
        public Fecha $fechaInicioPeriodo,
        public Fecha $fechaVencimiento,
        /** El divisor de la mora usa el período original aunque la cuota se reprograme (1.18, 12.4). */
        public Fecha $fechaVencimientoOriginal,
        public int $montoCapital,
        public int $montoInteres,
        public int $cargoMonto = 0,
        public int $moraCongelada = 0,
        /** Suma de condonaciones vigentes: vive fuera de las aplicaciones y sobrevive a los recálculos (1.6). */
        public int $moraCondonada = 0,
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
        );
    }

    /** Capital + interés: base sobre la que corre la mora (la mora no corre sobre el cargo, 1.18). */
    public function montoBaseMora(): int
    {
        return $this->montoCapital + $this->montoInteres;
    }
}
