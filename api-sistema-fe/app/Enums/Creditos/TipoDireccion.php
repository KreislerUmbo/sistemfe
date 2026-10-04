<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Tipo de dirección de cobro (1.23). */
enum TipoDireccion: string
{
    case Casa = 'casa';
    case Negocio = 'negocio';
}
