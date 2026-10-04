<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Límites ya resueltos (config del negocio → ajuste por cliente). Montos en centavos. */
final readonly class PoliticaLimites
{
    public function __construct(
        public int $maxCreditosActivos = 2,
        public ?int $deudaMaxima = null,
        public int $diasAtrasoBloqueo = 7,
        public int $maxGarantiasPorGarante = 3,
    ) {
    }
}
