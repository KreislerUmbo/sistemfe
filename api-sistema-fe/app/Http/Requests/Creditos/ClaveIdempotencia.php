<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

/** Regla común: toda escritura del módulo trae su clave de idempotencia (00 §4). */
final class ClaveIdempotencia
{
    /** @return array<string, list<string>> */
    public static function reglas(): array
    {
        return ['clave_idempotencia' => ['required', 'string', 'min:8', 'max:64']];
    }
}
