<?php

namespace App\Http\Requests\AgenciaViajes;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// Penalidad por quitar un pasajero en una modificación de reserva (T-004, R-AGE-05, D-AGE-033).
// El tratamiento de IGV es obligatorio y lo elige el vendedor: no hay valor por defecto
// (pendiente del contador). Los valores válidos son los mismos que acepta un ítem manual del
// cotizador. La autorización va en el controller (permiso de la ruta + ReservaPolicy).
class PenalidadPasajeroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reglas = [
            // Múltiplo de 0.01 (máx. 2 decimales); 0 es válido.
            'monto' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'motivo' => ['required', 'string', 'max:500'],
            'tip_afe_igv' => ['required', 'string', 'in:10,20,30'],
            'destino_tributario' => ['required', 'string', 'in:amazonia,nacional,extranjero'],
        ];

        // El pasajero solo se elige al crear: una penalidad no cambia de pasajero.
        if ($this->isMethod('POST')) {
            $reglas = ['reserva_pasajero_id' => ['required', 'integer']] + $reglas;
        }

        return $reglas;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'code' => 422,
            'message' => $validator->errors()->first(),
        ], 422));
    }
}
