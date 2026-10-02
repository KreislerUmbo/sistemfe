<?php

declare(strict_types=1);

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de clientes, todos los giros (04c P2). Antes no se validaba nada: se podía
 * guardar un DNI de 3 dígitos o un correo inválido. El número se normaliza (sin espacios,
 * mayúsculas) para que el índice único de documento no lo deje pasar escrito de otra forma.
 */
class ClienteRequest extends FormRequest
{
    public const TIPOS = ['DNI', 'RUC', 'CE', 'PAS', 'SND'];
    private const FORMATOS = [
        'DNI' => '/^\d{8}$/',
        'RUC' => '/^(10|15|16|17|20)\d{9}$/',
        'CE' => '/^[A-Z0-9]{6,12}$/',
        'PAS' => '/^[A-Z0-9]{5,12}$/',
    ];
    private const MENSAJES_FORMATO = [
        'DNI' => 'El DNI debe tener 8 dígitos.',
        'RUC' => 'El RUC debe tener 11 dígitos y empezar en 10, 15, 16, 17 o 20.',
        'CE' => 'El carné de extranjería debe tener de 6 a 12 letras o números.',
        'PAS' => 'El pasaporte debe tener de 5 a 12 letras o números.',
    ];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $tipo = strtoupper(trim((string) $this->input('type_document')));
        $limpio = static fn ($v) => is_string($v) ? trim(preg_replace('/\s+/', ' ', $v)) : $v;

        $this->merge([
            'type_document' => $tipo,
            'n_document' => $tipo === 'SND'
                ? ($this->filled('n_document') ? trim((string) $this->input('n_document')) : '00000000')
                : strtoupper(preg_replace('/\s+/', '', (string) $this->input('n_document'))),
            'name' => $limpio($this->input('name')),
            'surname' => $limpio($this->input('surname')),
            'full_name' => $limpio($this->input('full_name')),
            'name_comerc' => $limpio($this->input('name_comerc')),
            'email' => is_string($this->input('email')) && trim($this->input('email')) !== '' ? trim($this->input('email')) : null,
        ]);
    }

    public function rules(): array
    {
        $tipo = (string) $this->input('type_document');

        return [
            'type_document' => ['required', Rule::in(self::TIPOS)],
            'n_document' => array_filter([
                'required', 'string', 'max:20',
                isset(self::FORMATOS[$tipo]) ? 'regex:' . self::FORMATOS[$tipo] : null,
            ]),
            'cod_tipo_doc_sunat' => ['nullable', 'string', 'max:5'],
            'type_client' => ['nullable', 'integer'],
            'full_name' => ['required', 'string', 'max:250'],
            'name' => ['nullable', 'string', 'max:200'],
            'surname' => ['nullable', 'string', 'max:250'],
            'name_comerc' => ['nullable', 'string', 'max:250'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]*$/'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(['M', 'F'])],
            'address' => ['nullable', 'string', 'max:250'],
            'ubigeo_region' => ['nullable', 'string', 'max:25'],
            'ubigeo_provincia' => ['nullable', 'string', 'max:25'],
            'ubigeo_distrito' => ['nullable', 'string', 'max:25'],
            'region' => ['nullable', 'string', 'max:80'],
            'provincia' => ['nullable', 'string', 'max:80'],
            'distrito' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', Rule::in([1, 2, '1', '2'])],
            'regimen_tributario' => ['nullable', 'string', 'max:20'],
            'es_agente_retencion' => ['nullable', 'boolean'],
            'es_amazonia' => ['nullable', 'boolean'],
            // Cliente sin documento con un nombre ya registrado: se guarda solo si se confirma.
            'confirmar_nombre_repetido' => ['nullable', 'boolean'],
            // Créditos: asesor elegido al registrar (solo con creditos.cartera.asignar).
            'asesor_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'n_document.regex' => self::MENSAJES_FORMATO[(string) $this->input('type_document')] ?? 'El número de documento no es válido.',
            'type_document.in' => 'Elige un tipo de documento válido.',
            'phone.regex' => 'El teléfono solo puede tener números, espacios, +, - y paréntesis.',
            'birth_date.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'required' => 'Completa :attribute.',
            'email' => 'El correo no es válido.',
            'max' => ':attribute es demasiado largo.',
        ];
    }

    public function attributes(): array
    {
        return [
            'type_document' => 'el tipo de documento',
            'n_document' => 'el número de documento',
            'full_name' => 'el nombre',
            'email' => 'el correo',
            'phone' => 'el teléfono',
            'address' => 'la dirección',
        ];
    }
}
