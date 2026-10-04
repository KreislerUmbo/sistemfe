<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\EstadoCuota;
use App\Services\Creditos\Motor\Fecha;

/** Acumulados de una cuota derivados de las aplicaciones vigentes (1.9 paso 4). */
final readonly class CuotaSaldada
{
    public function __construct(
        public int $numero,
        public int $capitalPagado,
        public int $interesPagado,
        public int $cargoPagado,
        public int $moraPagada,
        public int $interesCondonado,
        public EstadoCuota $estado,
        public ?Fecha $fechaPago,
        public ?int $diasAtrasoAlPagar,
    ) {
    }
}
