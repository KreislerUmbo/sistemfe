<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Cómo se castigó un crédito (1.19). */
enum TipoCastigo: string
{
    case Automatico = 'automatico';
    case Manual = 'manual';
}
