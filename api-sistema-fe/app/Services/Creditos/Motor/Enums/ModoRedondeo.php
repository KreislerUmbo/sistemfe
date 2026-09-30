<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Sentido del redondeo; hacia abajo solo para la salvaguarda de 12.2. */
enum ModoRedondeo: string
{
    case MedioArriba = 'medio_arriba';
    case Abajo = 'abajo';
}
