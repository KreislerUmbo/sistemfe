<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Models\Creditos\Credito;
use App\Services\Creditos\Motor\Dto\ResultadoAplicacion;

/** Crédito con su situación calculada en vivo a hoy (mora, exigible). Montos en centavos. */
final readonly class DetalleCredito
{
    public function __construct(
        public Credito $credito,
        public ?ResultadoAplicacion $situacion,
        public int $exigible,
        public int $saldoCapital,
        public int $saldoInteres,
        public int $diasAtraso,
    ) {
    }
}
