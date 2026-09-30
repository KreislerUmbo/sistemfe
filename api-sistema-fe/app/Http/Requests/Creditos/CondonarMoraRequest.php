<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use Illuminate\Foundation\Http\FormRequest;

/** Condonar mora de una cuota (00 1.6); el tope es la mora pendiente de hoy, validada en el servicio. */
class CondonarMoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cuota_id' => ['required', 'integer'],
            'monto' => ['required', new MontoSoles()],
            'motivo' => ['required', 'string', 'max:500'],
            ...ClaveIdempotencia::reglas(),
        ];
    }
}
