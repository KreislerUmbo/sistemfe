<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Qué hacer con un vencimiento que cae en día no laborable (plan 1.4). */
enum ReglaNoLaborable: string
{
    case Siguiente = 'siguiente';
    case Anterior = 'anterior';
    case Mantener = 'mantener';
}
