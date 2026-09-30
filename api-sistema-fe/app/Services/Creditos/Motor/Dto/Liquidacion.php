<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/** Cotización de liquidación anticipada; vale solo para $fechaReferencia (1.5, 12.7). */
final readonly class Liquidacion
{
    /** @param list<RepartoLiquidacionCuota> $reparto */
    public function __construct(
        public Fecha $fechaReferencia,
        public int $capitalPendiente,
        public int $interesDevengado,
        public int $interesMinimo,
        public int $interesFinal,
        public int $interesCobrado,
        public int $interesACobrar,
        public int $interesCondonado,
        public int $cargoPendiente,
        public int $moraPendiente,
        public int $montoLiquidacion,
        public array $reparto,
    ) {
    }
}
