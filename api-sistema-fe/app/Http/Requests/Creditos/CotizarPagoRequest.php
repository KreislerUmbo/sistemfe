<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Rules\Creditos\MontoSoles;
use App\Services\Creditos\Motor\Enums\DestinoExcedente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cómo se aplicaría un monto (preview del cobro, sin escribir). */
class CotizarPagoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'monto_recibido' => ['required', new MontoSoles()],
            'destino_excedente' => ['nullable', Rule::enum(DestinoExcedente::class)],
            // Pago con fecha anterior: la vista previa se calcula a esa fecha (el permiso lo valida el servicio).
            'fecha_pago' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
