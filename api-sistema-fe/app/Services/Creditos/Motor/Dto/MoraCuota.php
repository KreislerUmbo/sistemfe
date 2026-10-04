<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Mora de una cuota a una fecha. Montos en centavos. */
final readonly class MoraCuota
{
    public function __construct(
        public int $numeroCuota,
        /** Días calendario desde el vencimiento mientras la cuota tenga saldo (0 si ya está pagada). */
        public int $diasAtraso,
        public int $moraGenerada,
        public int $moraCongelada,
        public int $moraPagada,
        public int $moraCondonada,
        public int $moraPendiente,
        public bool $topeAlcanzado,
    ) {
    }
}
