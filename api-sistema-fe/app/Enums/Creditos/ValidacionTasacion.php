<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Qué hacer si el préstamo supera el % de tasación (1.11). */
enum ValidacionTasacion: string
{
    case Ninguna = 'ninguna';
    case Advertir = 'advertir';
    case Bloquear = 'bloquear';
}
