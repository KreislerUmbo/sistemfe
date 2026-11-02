<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

/**
 * T-033 (R7) — el proyecto no tiene traducciones de Laravel (locale 'en'), así que los 422 del
 * cotizador salían "The nombre field is required.". Mensajes en español para las reglas que usan
 * estos controllers; cada uno pasa el nombre legible de sus campos. La respuesta lleva además
 * `errors` por campo, para que la pantalla lo muestre junto al input.
 */
trait ValidaEnEspanol
{
    private const MENSAJES_ES = [
        'required' => 'El campo :attribute es obligatorio.',
        'string' => 'El campo :attribute debe ser un texto.',
        'integer' => 'El campo :attribute debe ser un número entero.',
        'numeric' => 'El campo :attribute debe ser un número.',
        'boolean' => 'El campo :attribute debe ser verdadero o falso.',
        'array' => 'El campo :attribute debe ser una lista.',
        'date' => 'El campo :attribute no es una fecha válida.',
        'in' => 'El valor de :attribute no es válido.',
        'exists' => 'El :attribute seleccionado no existe.',
        'after_or_equal' => 'El campo :attribute debe ser igual o posterior a :date.',
        'min.numeric' => 'El campo :attribute debe ser al menos :min.',
        'min.array' => 'Debe haber al menos :min :attribute.',
        'min.string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'max.numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'max.string' => 'El campo :attribute no puede tener más de :max caracteres.',
        'max.array' => 'No puede haber más de :max :attribute.',
    ];

    protected function validadorEs(array $datos, array $reglas, array $atributos = []): \Illuminate\Validation\Validator
    {
        return Validator::make($datos, $reglas, self::MENSAJES_ES, $atributos);
    }

    protected function respuestaValidacionEs(\Illuminate\Validation\Validator $validator): JsonResponse
    {
        return response()->json([
            'code' => 422,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422);
    }
}
