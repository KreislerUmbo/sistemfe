<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// T-065 — alta y edición de "otra dirección" del cliente. Los permisos los pone la ruta.
class ClientAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $limpio = fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v;
        $campos = ['nombre', 'direccion', 'referencia', 'region', 'provincia', 'distrito',
            'ubigeo_region', 'ubigeo_provincia', 'ubigeo_distrito'];
        $this->merge(collect($campos)->mapWithKeys(fn ($c) => [$c => $limpio($this->input($c))])->all());
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'direccion' => ['required', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:200'],
            'ubigeo_region' => ['nullable', 'string', 'max:25'],
            'ubigeo_provincia' => ['nullable', 'string', 'max:25'],
            'ubigeo_distrito' => ['nullable', 'string', 'max:25'],
            // Columnas de 50 en client_addresses (no 80 como en clients).
            'region' => ['nullable', 'string', 'max:50'],
            'provincia' => ['nullable', 'string', 'max:50'],
            'distrito' => ['nullable', 'string', 'max:50'],
            // Solo en la edición: activar/desactivar. Al crear nace activa.
            'activo' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nombre' => 'el nombre', 'direccion' => 'la dirección', 'referencia' => 'la referencia'];
    }
}
