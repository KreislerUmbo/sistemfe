<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asesor y cobrador de un cliente (04c); null = sin asignar. Con asesor_cobra el cobrador se
 * ignora: es el mismo asesor.
 */
class AsignarCarteraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'asesor_id' => ['present', 'nullable', 'integer', 'exists:users,id'],
            'cobrador_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
