<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Renovación (00 1.12): condiciones del crédito nuevo; el cliente es el del crédito que se renueva. */
class RenovarRequest extends CreditoDatosRequest
{
    protected bool $requiereCliente = false;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Bloqueos de límites autorizados en el mismo acto (requiere creditos.autorizar_excepcion).
            'motivo_autorizacion' => ['nullable', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }
}
