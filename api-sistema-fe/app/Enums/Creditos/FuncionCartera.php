<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/**
 * Función del usuario en la cartera de un cliente (04c): quién coloca los créditos (asesor) y
 * quién los cobra (cobrador). Con credito_configuracion.asesor_cobra = true es la misma persona.
 */
enum FuncionCartera: string
{
    case Asesor = 'asesor';
    case Cobrador = 'cobrador';
}
