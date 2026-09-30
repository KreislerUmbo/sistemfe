<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Fecha;
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
        /** Fecha de castigo: la mora deja de correr desde aquí (1.19). */
        public ?Fecha $fechaCongelamiento = null,
        public int $pasoRedondeo = Redondeo::PASO_DIEZ_CENTIMOS,
    ) {
    }
}
