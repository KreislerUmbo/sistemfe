<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/**
 * Mora congelada al reprogramar una cuota vencida (00 1.9), visible solo desde la fecha de esa
 * reprogramación: al reaplicar pagos anteriores (1.8) no deben "ver" una deuda que todavía no
 * existía con esa forma. Monto en centavos.
 */
final readonly class MoraCongelada
{
    public function __construct(
        public Fecha $desde,
        public int $monto,
    ) {
    }
}
