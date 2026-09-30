<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Unidad de la frecuencia de pago (plan 1.3). */
enum FrecuenciaUnidad: string
{
    case Dia = 'dia';
    case Semana = 'semana';
    case Quincena = 'quincena';
    case Mes = 'mes';
    case Anio = 'anio';
}
