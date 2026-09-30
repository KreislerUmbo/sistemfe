<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Unidad en que se expresa la tasa pactada (plan 1.1). */
enum UnidadTasa: string
{
    case Total = 'total';
    case Mensual = 'mensual';
}
