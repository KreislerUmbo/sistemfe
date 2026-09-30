<?php

declare(strict_types=1);

namespace App\Services\Creditos\Motor\Enums;

/** Qué hacer con lo que supera lo exigible (plan 1.7 / 12.5). */
enum DestinoExcedente: string
{
    case Devolver = 'devolver';
    case Adelanto = 'adelanto';
    case SaldoAFavor = 'saldo_a_favor';
}
