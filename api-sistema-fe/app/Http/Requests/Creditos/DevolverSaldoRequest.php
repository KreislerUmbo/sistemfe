<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Devolver al cliente parte o todo su saldo a favor (04c.1): sale de la caja abierta. */
class DevolverSaldoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto' => ['required', new MontoSoles()],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'motivo' => ['required', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function centavos(): int
    {
        return Dinero::aCentavos($this->input('monto'));
    }
}
