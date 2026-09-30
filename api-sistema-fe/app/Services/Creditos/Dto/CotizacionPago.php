<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Dto\Aplicacion;

/** Cómo se aplicaría un monto hoy, antes de confirmar el cobro (00 1.4-1.5). Montos en centavos. */
final readonly class CotizacionPago
{
    /** @param list<Aplicacion> $lineas */
    public function __construct(
        public int $exigible,
        public int $montoAplicado,
        public int $montoExcedente,
        public array $lineas,
        public bool $finalizaCredito,
        /** Situación del crédito si se confirma el pago (saldo y próxima cuota). */
        public SaldoCredito $despues,
    ) {
    }
}
