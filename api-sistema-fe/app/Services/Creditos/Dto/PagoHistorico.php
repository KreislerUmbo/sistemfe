<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Pago anterior al sistema en una migración (00 1.12). Monto en centavos. */
final readonly class PagoHistorico
{
    public function __construct(
        public Fecha $fecha,
        public int $monto,
    ) {
    }
}
