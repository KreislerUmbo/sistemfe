<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Vista previa de una renovación: solo condiciones, sin escribir nada. */
class PreviewRenovacionRequest extends CreditoDatosRequest
{
    protected bool $requiereCliente = false;
}
