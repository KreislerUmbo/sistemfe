<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o edición de un feriado del negocio. */
class FeriadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date_format:Y-m-d', Rule::unique('feriados', 'fecha')->ignore($this->route('feriado'))],
            'descripcion' => ['required', 'string', 'max:150'],
        ];
    }
}
