<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;

/** Crédito con su situación calculada en vivo a hoy (mora, exigible, cabecera). Montos en centavos. */
final readonly class DetalleCredito
{
    public function __construct(
        public Credito $credito,
        public ?ResultadoAplicacion $situacion,
        public int $exigible,
        public int $saldoCapital,
        public int $saldoInteres,
        public int $diasAtraso,
        /** Cabecera (por pagar, pagado, próxima cuota, deuda de hoy); null si no tiene situación. */
        public ?SaldoCredito $saldo = null,
    ) {
    }
}
