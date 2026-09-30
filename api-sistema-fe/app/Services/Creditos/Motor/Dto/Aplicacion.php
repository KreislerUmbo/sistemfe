<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;

/** Fila de credito_pago_aplicaciones. */
final readonly class Aplicacion
{
    public function __construct(
        public int|string $referenciaPago,
        public int $numeroCuota,
        public ConceptoAplicacion $concepto,
        public int $monto,
    ) {
    }
}
