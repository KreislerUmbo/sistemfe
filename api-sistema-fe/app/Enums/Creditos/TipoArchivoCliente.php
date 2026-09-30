<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Archivo de la ficha de cobro del cliente (1.23). */
enum TipoArchivoCliente: string
{
    case DniAnverso = 'dni_anverso';
    case DniReverso = 'dni_reverso';
    case FotoCliente = 'foto_cliente';
    case Otro = 'otro';
}
