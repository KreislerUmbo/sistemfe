<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Excepciones;

/** Un pago con destino "adelanto" aplicaría más que el monto de liquidación a la fecha (12.5): usar Liquidar. */
final class PagoExcedeDeuda extends \DomainException
{
    public function __construct(
        public readonly int $montoAplicable,
        public readonly int $montoLiquidacion,
    ) {
        parent::__construct(
            "El pago aplicaría {$montoAplicable} centavos y la liquidación a la fecha es {$montoLiquidacion}; use Liquidar."
        );
    }
}
