<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Por qué un crédito quedó finalizado. */
enum MotivoCierre: string
{
    case PagadoCompleto = 'pagado_completo';
    case LiquidacionAnticipada = 'liquidacion_anticipada';
    case VentaPrenda = 'venta_prenda';
    case Renovacion = 'renovacion';
    case RecuperoCastigo = 'recupero_castigo';
}
