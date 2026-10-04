<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Origen de un cargo sumado a una cuota (1.18). */
enum TipoCargo: string
{
    case Reprogramacion = 'reprogramacion';
}
