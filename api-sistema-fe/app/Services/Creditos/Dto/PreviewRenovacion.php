<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Dto\Cronograma;
use App\Services\Creditos\Motor\Dto\Liquidacion;
use App\Services\Creditos\Motor\Dto\ResultadoLimites;

/** Vista previa de una renovación (00 1.12). Montos en centavos. */
final readonly class PreviewRenovacion
{
    public function __construct(
        public Liquidacion $liquidacion,
        public int $capitalNuevo,
        public int $entregaNeta,
        public Cronograma $cronograma,
        public ResultadoLimites $limites,
    ) {
    }
}
