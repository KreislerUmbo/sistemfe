<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Corregir un crédito activo sin pagos (00 1.9): condiciones nuevas + motivo. El cliente no cambia. */
class CorregirCreditoRequest extends CreditoDatosRequest
{
    protected bool $requiereCliente = false;

    public function rules(): array
    {
        return [...parent::rules(), 'motivo' => ['required', 'string', 'max:500'], ...ClaveIdempotencia::reglas()];
    }
}
