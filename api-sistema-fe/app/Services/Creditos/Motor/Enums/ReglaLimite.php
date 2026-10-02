<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Reglas de otorgamiento evaluadas por EvaluadorLimites (plan 1.17 / 12.11). */
enum ReglaLimite: string
{
    case MaxCreditos = 'max_creditos';
    case DeudaMaxima = 'deuda_maxima';
    case Moroso = 'moroso';
    case Bloqueado = 'bloqueado';
    /** Faltan datos de la ficha exigidos por la configuración (04c). */
    case FichaIncompleta = 'ficha_incompleta';
    case PropioGarante = 'propio_garante';
    case GaranteMoroso = 'garante_moroso';
    case GaranteSaturado = 'garante_saturado';
}
