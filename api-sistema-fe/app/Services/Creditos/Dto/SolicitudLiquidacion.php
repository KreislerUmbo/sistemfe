<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

/** Liquidación anticipada confirmada (00 1.7). Monto en centavos. */
final readonly class SolicitudLiquidacion
{
    public function __construct(
        public int $montoRecibido,
        public int $paymentMethodId,
        public string $clave,
        public ?string $referencia = null,
    ) {
    }
}
