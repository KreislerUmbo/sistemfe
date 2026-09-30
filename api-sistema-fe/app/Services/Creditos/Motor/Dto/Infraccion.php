<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\ReglaLimite;

/** Una regla de otorgamiento incumplida. */
final readonly class Infraccion
{
    /** @param array<string, int|null> $detalle valores al momento (json de credito_autorizaciones) */
    public function __construct(
        public ReglaLimite $regla,
        public bool $bloquea,
        public bool $autorizable,
        public array $detalle = [],
    ) {
    }
}
