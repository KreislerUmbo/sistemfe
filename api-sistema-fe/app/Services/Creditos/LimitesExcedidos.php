<?php

declare(strict_types=1);

namespace App\Services\Creditos;

use App\Services\Creditos\Motor\Dto\Infraccion;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Límites de otorgamiento que bloquean y no tienen autorización (00 1.10). Se responde 422 con el detalle. */
final class LimitesExcedidos extends HttpException
{
    /** @param list<Infraccion> $infracciones */
    public function __construct(public readonly array $infracciones)
    {
        parent::__construct(422, 'El crédito excede los límites de otorgamiento y requiere autorización de un administrador.');
    }

    /** @return list<array<string, mixed>> */
    public function detalle(): array
    {
        return array_map(static fn (Infraccion $i): array => [
            'regla' => $i->regla->value,
            'autorizable' => $i->autorizable,
            'detalle' => $i->detalle,
        ], $this->infracciones);
    }
}
