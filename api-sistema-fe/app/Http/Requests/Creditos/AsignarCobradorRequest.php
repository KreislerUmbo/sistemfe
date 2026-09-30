<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use Illuminate\Foundation\Http\FormRequest;

/** Asignar (o quitar, con null) el cobrador de un cliente (03-api decisión 4). */
class AsignarCobradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['cobrador_id' => ['present', 'nullable', 'integer', 'exists:users,id']];
    }
}
