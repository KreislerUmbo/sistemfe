<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Cuota generada por GeneradorCronograma. Montos en centavos. */
final readonly class CuotaProgramada
{
    public function __construct(
        public int $numero,
        public Fecha $fechaInicioPeriodo,
        public Fecha $fechaVencimiento,
        public int $montoCapital,
        public int $montoInteres,
        public int $montoTotal,
        /** 12.3: la regla `anterior` era imposible y se aplicó `siguiente` (el preview lo indica). */
        public bool $fechaForzadaASiguiente = false,
        /** Fecha antes del ajuste por día no laborable o feriado; null si no se movió. */
        public ?Fecha $fechaTeorica = null,
    ) {
    }
}
