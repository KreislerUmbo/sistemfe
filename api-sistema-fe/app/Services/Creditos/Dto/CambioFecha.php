<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Una cuota reprogramada (00 1.9). Monto en centavos. */
final readonly class CambioFecha
{
    public function __construct(
        public int $numeroCuota,
        public Fecha $fechaAnterior,
        public Fecha $fechaNueva,
        /** Mora ya generada que se congela en la cuota (solo si estaba vencida). */
        public int $moraCongelada,
        public bool $caeEnNoLaborable,
    ) {
    }
}
