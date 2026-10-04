<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Dto;

/** Situación de un garante propuesto (1.17). */
final readonly class ResumenGarante
{
    public function __construct(
        public int $clienteId,
        public int $diasAtrasoComoTitular,
        public int $garantiasActivas,
    ) {
    }
}
