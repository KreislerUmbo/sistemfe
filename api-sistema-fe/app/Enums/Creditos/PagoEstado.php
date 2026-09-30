<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Estado de un pago; nunca se borra (1.9). */
enum PagoEstado: string
{
    case Valido = 'valido';
    case Anulado = 'anulado';
}
