<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Estado de un crédito (plan §2 creditos.estado). */
enum CreditoEstado: string
{
    case Borrador = 'borrador';
    case Activo = 'activo';
    case Castigado = 'castigado';
    case Finalizado = 'finalizado';
    case Anulado = 'anulado';
}
