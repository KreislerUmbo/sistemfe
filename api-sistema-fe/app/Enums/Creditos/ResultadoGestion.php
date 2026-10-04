<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Resultado de una gestión de cobranza (1.14). */
enum ResultadoGestion: string
{
    case Pago = 'pago';
    case NoEncontrado = 'no_encontrado';
    case PromesaPago = 'promesa_pago';
    case SeNiega = 'se_niega';
    case Otro = 'otro';
}
