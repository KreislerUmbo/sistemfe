<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Ciclo de una prenda (1.11). */
enum PrendaEstado: string
{
    case EnCustodia = 'en_custodia';
    case AptaParaVenta = 'apta_para_venta';
    case EnVenta = 'en_venta';
    case Vendida = 'vendida';
    case Devuelta = 'devuelta';
}
