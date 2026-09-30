<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Documento guardado de un crédito (1.15). */
enum TipoDocumentoCredito: string
{
    case Contrato = 'contrato';
    case ContratoFirmado = 'contrato_firmado';
}
