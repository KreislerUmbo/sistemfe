<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Qué se hizo con la mora acumulada al reprogramar (1.18). */
enum AccionMoraReprogramacion: string
{
    case Mantener = 'mantener';
    case Condonar = 'condonar';
    case NoAplica = 'no_aplica';
}
