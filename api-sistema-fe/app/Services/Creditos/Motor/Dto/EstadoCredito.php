<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Redondeo;
use App\Services\Creditos\Motor\Tasa;

/** Crédito activo: condiciones congeladas + cuotas vigentes. Entrada de mora, liquidación y aplicador. */
final readonly class EstadoCredito
{
    /** @param list<CuotaVigente> $cuotas ordenadas por número */
    public function __construct(
        public int $montoCapital,
        public int $interesTotal,
        public Tasa $tasaInteresMinimo,
        public array $cuotas,
        public ReglasMora $reglasMora = new ReglasMora(),
        public ReglasCalendario $calendario = new ReglasCalendario(),
        public int $pasoRedondeo = Redondeo::PASO_DIEZ_CENTIMOS,
    ) {
    }
}
