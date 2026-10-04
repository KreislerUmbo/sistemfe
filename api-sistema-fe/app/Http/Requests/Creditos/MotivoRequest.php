<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;

/** Acciones que solo piden motivo (anular crédito/pago, castigar, revertir castigo) (00 §4). */
class MotivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'max:500'], ...ClaveIdempotencia::reglas()];
    }
}
