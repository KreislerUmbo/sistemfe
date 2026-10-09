<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Traspasar toda la cartera de un usuario a otro (04c.1). */
class TraspasarCarteraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // El que entrega puede estar eliminado (soft delete): no se valida contra usuarios activos.
            'desde_usuario_id' => ['required', 'integer', 'min:1'],
            'hacia_usuario_id' => ['required', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'funciones' => ['nullable', Rule::in(['ambas', 'asesor', 'cobrador'])],
            // 08-oct-2026: pasar también los créditos activos/castigados que colocó (con motivo).
            'con_creditos' => ['nullable', 'boolean'],
            'motivo_creditos' => ['nullable', 'required_if_accepted:con_creditos', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }

    public function messages(): array
    {
        return [
            'hacia_usuario_id.exists' => 'El usuario que recibe la cartera no existe o fue eliminado.',
            'motivo_creditos.required_if_accepted' => 'Indica el motivo para pasar también los créditos.',
        ];
    }
}
