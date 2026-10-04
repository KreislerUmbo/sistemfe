<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\SolicitudLiquidacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Confirmar la liquidación (00 1.7); el monto se compara con la liquidación recalculada a hoy. */
class LiquidarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_recibido' => ['required', new MontoSoles()],
            'payment_method_id' => ['required', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', true)],
            'referencia' => ['nullable', 'string', 'max:100'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function aSolicitud(): SolicitudLiquidacion
    {
        return new SolicitudLiquidacion(
            Dinero::aCentavos($this->input('monto_recibido')),
            (int) $this->input('payment_method_id'),
            (string) $this->input('clave_idempotencia'),
            $this->input('referencia'),
        );
    }
}
