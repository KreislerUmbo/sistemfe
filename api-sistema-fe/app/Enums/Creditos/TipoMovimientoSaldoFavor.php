<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Movimiento del saldo a favor en créditos (1.7). */
enum TipoMovimientoSaldoFavor: string
{
    case Abono = 'abono';
    case Uso = 'uso';
    case Devolucion = 'devolucion';
    case Reverso = 'reverso';
}
