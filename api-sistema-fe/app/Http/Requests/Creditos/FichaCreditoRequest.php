<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Enums\Creditos\TipoDireccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ficha de cobro del cliente. */
class FichaCreditoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'direccion_cobro' => ['nullable', 'string', 'max:255'],
            'tipo_direccion' => ['nullable', Rule::enum(TipoDireccion::class)],
            'referencia' => ['nullable', 'string', 'max:255'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'telefono_alterno' => ['nullable', 'string', 'max:20'],
            'ocupacion' => ['nullable', 'string', 'max:150'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
