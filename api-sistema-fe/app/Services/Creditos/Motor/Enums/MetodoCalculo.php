<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Método de cálculo del interés; v1 solo simple_fijo (plan 1.1). */
enum MetodoCalculo: string
{
    case SimpleFijo = 'simple_fijo';
}
