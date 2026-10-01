<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Redondeo;

/** Reglas de mora congeladas en el crédito (plan 1.6, 1.19, 12.4). */
final readonly class ReglasMora
{
    public function __construct(
        public int $diasGracia = 0,
        public bool $cuentaNoLaborables = true,
        public TopeMoraTipo $topeTipo = TopeMoraTipo::PorcentajeCuota,
        /** Porcentaje entero (tipos porcentaje_*) o número de días (dias_maximos). */
        public ?int $topeValor = 100,
        /** @var list<PeriodoCastigo> Períodos castigados: no generan mora (1.19, 12.12). */
        public array $periodosCastigo = [],
        public int $pasoRedondeo = Redondeo::PASO_DIEZ_CENTIMOS,
        /** false = crédito sin interés moratorio: no se genera mora (el atraso igual se cuenta). */
        public bool $cobraMora = true,
    ) {
    }
}
