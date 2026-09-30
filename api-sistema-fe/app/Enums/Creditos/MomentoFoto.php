<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Cuándo se tomó una foto de prenda (1.11). */
enum MomentoFoto: string
{
    case Ingreso = 'ingreso';
    case Devolucion = 'devolucion';
}
