<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Tope de la mora (plan 1.19). */
enum TopeMoraTipo: string
{
    case PorcentajeCuota = 'porcentaje_cuota';
    case PorcentajeCapital = 'porcentaje_capital';
    case DiasMaximos = 'dias_maximos';
    case SinTope = 'sin_tope';
}
