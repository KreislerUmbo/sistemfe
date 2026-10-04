<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Enums\Creditos\AccionMoraReprogramacion;
use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Dinero;
use App\Services\Creditos\Dto\SolicitudReprogramacion;
use App\Services\Creditos\Motor\Fecha;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Reprogramar fechas (00 1.9): desplazar N días desde una cuota o editar fechas una por una. */
class ReprogramarRequest extends FormRequest
{
    /** El preview usa las mismas reglas sin motivo ni clave. */
    protected bool $esPreview = false;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'modo' => ['required', 'in:desplazar,editar'],
            'desde_cuota' => ['required_if:modo,desplazar', 'nullable', 'integer', 'min:1'],
            'dias' => ['required_if:modo,desplazar', 'nullable', 'integer', 'not_in:0', 'between:-365,365'],
            'fechas' => ['required_if:modo,editar', 'array'],
            'fechas.*' => ['date_format:Y-m-d'],
            'accion_mora' => ['nullable', Rule::in([AccionMoraReprogramacion::Mantener->value, AccionMoraReprogramacion::Condonar->value])],
            'cargo' => ['nullable', 'regex:/^\d+(\.\d{1,2})?$/'],
            ...($this->esPreview ? [] : ['motivo' => ['required', 'string', 'max:500'], ...ClaveIdempotencia::reglas()]),
        ];
    }

    public function aSolicitud(): SolicitudReprogramacion
    {
        $desplazar = $this->input('modo') === 'desplazar';
        $fechas = [];
        foreach ($desplazar ? [] : $this->input('fechas', []) as $numero => $fecha) {
            $fechas[(int) $numero] = Fecha::desdeTexto($fecha);
        }

        return new SolicitudReprogramacion(
            $desplazar ? (int) $this->input('desde_cuota') : null,
            $desplazar ? (int) $this->input('dias') : null,
            $fechas,
            AccionMoraReprogramacion::tryFrom((string) $this->input('accion_mora')) ?? AccionMoraReprogramacion::Mantener,
            $this->filled('cargo') ? Dinero::aCentavos((string) $this->input('cargo')) : null,
            (string) $this->input('motivo', ''),
            (string) $this->input('clave_idempotencia', ''),
        );
    }
}
