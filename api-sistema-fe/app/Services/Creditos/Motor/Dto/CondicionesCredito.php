<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\MetodoCalculo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Redondeo;
use App\Services\Creditos\Motor\Tasa;

/** Condiciones para generar un cronograma. Montos en centavos. */
final readonly class CondicionesCredito
{
    public function __construct(
        public int $montoCapital,
        public Tasa $tasa,
        public UnidadTasa $unidadTasa,
        public Frecuencia $frecuencia,
        public int $numeroCuotas,
        public Fecha $fechaDesembolso,
        public ?Fecha $fechaPrimerVencimiento = null,
        public ReglasCalendario $calendario = new ReglasCalendario(),
        public MetodoCalculo $metodo = MetodoCalculo::SimpleFijo,
        public int $pasoRedondeo = Redondeo::PASO_DIEZ_CENTIMOS,
    ) {
    }
}
