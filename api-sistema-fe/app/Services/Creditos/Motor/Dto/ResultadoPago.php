<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Cómo quedó un pago tras el reparto. */
final readonly class ResultadoPago
{
    public function __construct(
        public int|string $referenciaPago,
        public int $montoAplicado,
        public int $montoExcedente,
        public bool $fueLiquidacion,
        /** Pago posterior al castigo (12.11). Derivado: si se revierte el castigo deja de serlo. */
        public bool $esRecupero,
    ) {
    }
}
