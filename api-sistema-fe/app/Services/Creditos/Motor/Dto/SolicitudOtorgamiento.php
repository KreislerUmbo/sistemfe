<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Datos del cliente y del nuevo crédito para evaluar límites (1.17, 12.11). */
final readonly class SolicitudOtorgamiento
{
    /**
     * @param list<ResumenCreditoCliente> $creditosActivos incluye castigados (su deuda sigue vigente)
     * @param list<ResumenGarante> $garantes
     * @param list<string> $fichaFaltante requisitos de la ficha que el cliente no cumple (04c)
     */
    public function __construct(
        public int $clienteId,
        public int $montoCapitalNuevo,
        public array $creditosActivos,
        public bool $clienteBloqueado = false,
        public array $garantes = [],
        public ?int $creditoARenovarId = null,
        public bool $esMigracion = false,
        public array $fichaFaltante = [],
    ) {
    }
}
