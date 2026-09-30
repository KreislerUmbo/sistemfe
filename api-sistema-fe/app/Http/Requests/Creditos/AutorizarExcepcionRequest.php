<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Services\Creditos\Motor\Enums\ReglaLimite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Autorizar una excepción de límite sobre un borrador (00 1.10). */
class AutorizarExcepcionRequest extends FormRequest
{
    private const AUTORIZABLES = [ReglaLimite::MaxCreditos, ReglaLimite::DeudaMaxima, ReglaLimite::Moroso, ReglaLimite::Bloqueado];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'regla' => ['required', Rule::in(array_map(static fn (ReglaLimite $r): string => $r->value, self::AUTORIZABLES))],
            'motivo' => ['required', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }
}
