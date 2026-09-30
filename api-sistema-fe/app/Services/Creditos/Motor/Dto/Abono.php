<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\ConceptoAplicacion;
use App\Services\Creditos\Motor\Fecha;

/** Parte de un pago aplicada a una cuota, con su fecha: de aquí se reconstruyen los tramos de mora. */
final readonly class Abono
{
    public function __construct(
        public Fecha $fecha,
        public ConceptoAplicacion $concepto,
        public int $monto,
    ) {
    }
}
