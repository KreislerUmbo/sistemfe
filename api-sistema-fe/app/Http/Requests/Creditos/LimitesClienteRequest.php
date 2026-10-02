<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;

/** Límites propios del cliente y bloqueo manual (1.17); vacío = usar la configuración. */
class LimitesClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_creditos_activos' => ['nullable', 'integer', 'min:1', 'max:100'],
            'deuda_maxima' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'bloqueado' => ['required', 'boolean'],
            'motivo_bloqueo' => ['required_if:bloqueado,true', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['motivo_bloqueo.required_if' => 'Indica el motivo del bloqueo.'];
    }

    /** @return array{max_creditos_activos: int|null, deuda_maxima: string|null, bloqueado: bool, motivo_bloqueo: string|null} */
    public function datos(): array
    {
        return [
            'max_creditos_activos' => $this->filled('max_creditos_activos') ? (int) $this->input('max_creditos_activos') : null,
            'deuda_maxima' => $this->filled('deuda_maxima') ? (string) $this->input('deuda_maxima') : null,
            'bloqueado' => $this->boolean('bloqueado'),
            'motivo_bloqueo' => $this->filled('motivo_bloqueo') ? trim((string) $this->input('motivo_bloqueo')) : null,
        ];
    }
}
