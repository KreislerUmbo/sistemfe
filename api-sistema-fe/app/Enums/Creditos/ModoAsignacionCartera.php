<?php

declare(strict_types=1);

namespace App\Enums\Creditos;

/** Modo de asignación de cartera y tipo de asignación (1.14). */
enum ModoAsignacionCartera: string
{
    case Cliente = 'cliente';
    case Credito = 'credito';
    case Zona = 'zona';
}
