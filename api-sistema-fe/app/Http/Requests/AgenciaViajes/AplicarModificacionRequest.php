<?php

namespace App\Http\Requests\AgenciaViajes;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

// Aplicar una modificación de reserva (T-005, R-AGE-07). Lo único que viene del cliente es la
// huella de la diferencia que vio el usuario (sha256 en hexadecimal); el servicio la compara
// con la que recalcula bajo lock. La autorización va en el controller (permiso de la ruta +
// ReservaPolicy).
class AplicarModificacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'huella' => ['required', 'string', 'size:64'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'code' => 422,
            'message' => $validator->errors()->first(),
        ], 422));
    }
}
