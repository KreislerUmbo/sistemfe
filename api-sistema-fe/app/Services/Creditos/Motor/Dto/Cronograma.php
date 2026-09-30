<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Resultado de GeneradorCronograma. Montos en centavos. */
final readonly class Cronograma
{
    /** @param list<CuotaProgramada> $cuotas */
    public function __construct(
        public int $interesTotal,
        public int $montoTotal,
        public array $cuotas,
    ) {
    }
}
