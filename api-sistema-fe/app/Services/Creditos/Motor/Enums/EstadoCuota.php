<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Estado de una cuota. anulada solo para versiones de cronograma reemplazadas (plan 1.8). */
enum EstadoCuota: string
{
    case Pendiente = 'pendiente';
    case Pagada = 'pagada';
    case Anulada = 'anulada';
}
