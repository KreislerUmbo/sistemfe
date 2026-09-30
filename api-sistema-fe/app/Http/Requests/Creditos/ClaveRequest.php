<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;

/** Escrituras sin más datos que la clave de idempotencia (ej. activar un borrador). */
class ClaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ClaveIdempotencia::reglas();
    }
}
