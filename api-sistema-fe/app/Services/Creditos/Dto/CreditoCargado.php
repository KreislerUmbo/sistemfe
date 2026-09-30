<?php

declare(strict_types=1);

namespace App\Services\Creditos\Dto;

use App\Models\Creditos\CreditoCuota;
use App\Services\Creditos\Motor\Dto\EstadoCredito;
use App\Services\Creditos\Motor\Dto\PagoAAplicar;

/** Crédito listo para el motor: estado + pagos válidos + cuotas vigentes por número. */
final readonly class CreditoCargado
{
    /**
     * @param list<PagoAAplicar> $pagos pagos válidos en forma de reaplicación (referencia = credito_pagos.id)
     * @param array<int, CreditoCuota> $cuotasPorNumero
     */
    public function __construct(
        public EstadoCredito $estado,
        public array $pagos,
        public array $cuotasPorNumero,
    ) {
    }
}
