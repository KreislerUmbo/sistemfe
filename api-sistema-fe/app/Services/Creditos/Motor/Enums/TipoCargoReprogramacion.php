<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Cálculo del cargo por reprogramación (plan 1.18). */
enum TipoCargoReprogramacion: string
{
    case Ninguno = 'ninguno';
    case Fijo = 'fijo';
    case InteresPorDias = 'interes_por_dias';
}
