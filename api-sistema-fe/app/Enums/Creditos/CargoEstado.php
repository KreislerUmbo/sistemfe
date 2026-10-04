<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Estado de un cargo por reprogramación (1.18). */
enum CargoEstado: string
{
    case Vigente = 'vigente';
    case Anulado = 'anulado';
}
