<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Origen de un feriado (1.4). */
enum OrigenFeriado: string
{
    case Nacional = 'nacional';
    case Propio = 'propio';
}
