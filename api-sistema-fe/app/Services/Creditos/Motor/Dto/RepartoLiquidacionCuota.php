<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Cómo se reparte el pago de liquidación en una cuota pendiente (1.5). */
final readonly class RepartoLiquidacionCuota
{
    public function __construct(
        public int $numeroCuota,
        public int $capital,
        public int $interes,
        public int $interesCondonado,
        public int $cargo,
        public int $mora,
    ) {
    }
}
