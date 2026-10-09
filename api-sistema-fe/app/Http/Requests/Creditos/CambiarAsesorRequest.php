<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cambiar quién figura como asesor (quién colocó) de un crédito activo o castigado. */
class CambiarAsesorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asesor_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'motivo' => ['required', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function messages(): array
    {
        return ['asesor_id.exists' => 'El usuario elegido no existe o fue eliminado.'];
    }
}
