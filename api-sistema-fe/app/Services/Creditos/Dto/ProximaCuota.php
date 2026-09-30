<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Próxima cuota por vencer (vence hoy o después) y lo que falta pagarle. Monto en centavos. */
final readonly class ProximaCuota
{
    public function __construct(
        public int $numeroCuota,
        public Fecha $fechaVencimiento,
        public int $pendiente,
    ) {
    }
}
