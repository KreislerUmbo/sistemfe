<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Services\Creditos\Motor\Dto\Frecuencia;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use App\Services\Creditos\Motor\Enums\UnidadTasa;
use App\Services\Creditos\Motor\Fecha;
use App\Services\Creditos\Motor\Tasa;

/** Condiciones de un crédito ya validadas y con los defaults de la configuración aplicados. Montos en centavos. */
final readonly class DatosCredito
{
    /** @param list<int> $diasNoLaborables */
    public function __construct(
        public int $clienteId,
        public int $montoCapital,
        public Tasa $tasa,
        public UnidadTasa $unidadTasa,
        public Frecuencia $frecuencia,
        public int $numeroCuotas,
        public Fecha $fechaDesembolso,
        public ?Fecha $fechaPrimerVencimiento,
        public array $diasNoLaborables,
        public bool $saltarFeriados,
        public ReglaNoLaborable $reglaNoLaborable,
        public bool $moraCuentaNoLaborables,
        public Tasa $tasaInteresMinimo,
        public int $diasGracia,
        public TopeMoraTipo $topeMoraTipo,
        public ?int $topeMoraValor,
        public int $pasoRedondeo,
        public ?int $paymentMethodId,
    ) {
    }

    public function conCliente(int $clienteId): self
    {
        return new self(
            $clienteId, $this->montoCapital, $this->tasa, $this->unidadTasa, $this->frecuencia, $this->numeroCuotas,
            $this->fechaDesembolso, $this->fechaPrimerVencimiento, $this->diasNoLaborables, $this->saltarFeriados,
            $this->reglaNoLaborable, $this->moraCuentaNoLaborables, $this->tasaInteresMinimo, $this->diasGracia,
            $this->topeMoraTipo, $this->topeMoraValor, $this->pasoRedondeo, $this->paymentMethodId,
        );
    }
}
