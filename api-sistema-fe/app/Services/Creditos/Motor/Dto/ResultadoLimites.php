<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

use App\Services\Creditos\Motor\Enums\ReglaLimite;

/** Resultado de EvaluadorLimites. */
final readonly class ResultadoLimites
{
    /** @param list<Infraccion> $infracciones */
    public function __construct(public array $infracciones)
    {
    }

    public function bloquea(): bool
    {
        return $this->bloqueos() !== [];
    }

    /** Hay bloqueos y todos se pueden autorizar con creditos.autorizar_excepcion. */
    public function esAutorizable(): bool
    {
        $bloqueos = $this->bloqueos();

        return $bloqueos !== []
            && array_filter($bloqueos, static fn (Infraccion $i): bool => ! $i->autorizable) === [];
    }

    /** @return list<Infraccion> */
    public function bloqueos(): array
    {
        return array_values(array_filter($this->infracciones, static fn (Infraccion $i): bool => $i->bloquea));
    }

    /** @return list<Infraccion> */
    public function advertencias(): array
    {
        return array_values(array_filter($this->infracciones, static fn (Infraccion $i): bool => ! $i->bloquea));
    }

    public function infraccion(ReglaLimite $regla): ?Infraccion
    {
        foreach ($this->infracciones as $infraccion) {
            if ($infraccion->regla === $regla) {
                return $infraccion;
            }
        }

        return null;
    }
}
