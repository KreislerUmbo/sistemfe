<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Enums\Creditos\TipoArchivoCliente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** DNI, foto del cliente u otro archivo (imagen hasta 10 MB; se comprime al guardar). */
class ArchivoClienteRequest extends FormRequest
{
    private const MAXIMO_KB = 10_240;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoArchivoCliente::class)],
            'archivo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAXIMO_KB],
        ];
    }
}
