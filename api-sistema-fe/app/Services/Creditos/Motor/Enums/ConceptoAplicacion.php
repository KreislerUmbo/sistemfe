<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Concepto al que se aplica una parte de un pago (plan 1.7). */
enum ConceptoAplicacion: string
{
    case Interes = 'interes';
    case Capital = 'capital';
    case Cargo = 'cargo';
    case Mora = 'mora';
}
