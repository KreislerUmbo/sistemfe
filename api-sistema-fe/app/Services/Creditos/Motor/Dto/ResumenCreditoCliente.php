<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Crédito activo o castigado del cliente, para evaluar límites (12.11). */
final readonly class ResumenCreditoCliente
{
    public function __construct(
        public int $creditoId,
        public int $saldoCapital,
        public int $diasAtraso,
    ) {
    }
}
