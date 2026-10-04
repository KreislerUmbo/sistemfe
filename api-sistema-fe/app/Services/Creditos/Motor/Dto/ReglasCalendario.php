<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Fecha;

/** Días no laborables de un crédito (plan 1.4). Los feriados llegan como parámetro: el motor no consulta BD. */
final readonly class ReglasCalendario
{
    /**
     * @param list<int> $diasNoLaborables días ISO 1-7
     * @param list<Fecha> $feriados
     */
    public function __construct(
        public array $diasNoLaborables = [7],
        public bool $saltarFeriados = false,
        public array $feriados = [],
        public ReglaNoLaborable $regla = ReglaNoLaborable::Siguiente,
    ) {
    }
}
