<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Cómo se originó el crédito (1.20, 1.21). */
enum OrigenRegistro: string
{
    case Normal = 'normal';
    case Migracion = 'migracion';
    case Renovacion = 'renovacion';
}
