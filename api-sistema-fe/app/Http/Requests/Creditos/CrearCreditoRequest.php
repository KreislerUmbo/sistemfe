<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Crear o editar un borrador: condiciones + clave de idempotencia. */
class CrearCreditoRequest extends CreditoDatosRequest
{
    public function rules(): array
    {
        return [...parent::rules(), ...ClaveIdempotencia::reglas()];
    }
}
