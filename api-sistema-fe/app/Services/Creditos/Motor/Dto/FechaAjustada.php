<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Fecha tras aplicar la regla de día no laborable. */
final readonly class FechaAjustada
{
    public function __construct(
        public Fecha $fecha,
        public bool $forzadaASiguiente = false,
    ) {
    }
}
