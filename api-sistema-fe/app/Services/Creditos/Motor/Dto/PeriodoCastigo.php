<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Fecha;

/**
 * Período en que el crédito estuvo castigado (credito_castigos, 1.19, 12.12). La mora no
 * corre en los días (desde, hasta]: el día del castigo aún genera mora y se reanuda el día
 * siguiente a la reversión. hasta null = castigo vigente.
 */
final readonly class PeriodoCastigo
{
    public function __construct(
        public Fecha $desde,
        public ?Fecha $hasta = null,
    ) {
        if ($hasta !== null && ! $hasta->esPosteriorA($desde)) {
            throw new \InvalidArgumentException('La reversión del castigo debe ser posterior al castigo.');
        }
    }

    public function estaVigente(): bool
    {
        return $this->hasta === null;
    }
}
