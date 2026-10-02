<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Giro del tenant de la petición actual ('retail', 'agencia_viajes', 'creditos'…). Clase propia
 * y no tenant()->giro suelto para que los tests (sin tenancy inicializada) puedan fijarlo con
 * app()->instance(GiroActual::class, new GiroActual('creditos')).
 */
class GiroActual
{
    public const CREDITOS = 'creditos';

    public function __construct(private readonly ?string $forzado = null)
    {
    }

    public function valor(): ?string
    {
        return $this->forzado ?? tenant()?->giro;
    }

    public function es(string $giro): bool
    {
        return $this->valor() === $giro;
    }
}
