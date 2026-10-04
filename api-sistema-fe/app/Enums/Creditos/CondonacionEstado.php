<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Estado de una condonación de mora (1.6). */
enum CondonacionEstado: string
{
    case Vigente = 'vigente';
    case Anulada = 'anulada';
}
