<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Tipo de prenda en garantía (1.11). */
enum PrendaTipo: string
{
    case Equipo = 'equipo';
    case Vehiculo = 'vehiculo';
}
