<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Contadores del módulo (credito_correlativos). */
enum TipoCorrelativo: string
{
    case Credito = 'credito';
    case Recibo = 'recibo';
}
