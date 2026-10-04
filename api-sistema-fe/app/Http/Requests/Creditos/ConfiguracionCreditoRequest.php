<?php

declare(strict_types=1);

namespace App\Http\Requests\Creditos;

use App\Enums\Creditos\RequisitoFicha;
use App\Enums\Creditos\ValidacionTasacion;
use App\Services\Creditos\Motor\Enums\ReglaNoLaborable;
use App\Services\Creditos\Motor\Enums\TipoCargoReprogramacion;
use App\Services\Creditos\Motor\Enums\TopeMoraTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Configuración del módulo (defaults que se copian a cada crédito nuevo). */
class ConfiguracionCreditoRequest extends FormRequest
{
    private const DIAS = ['integer', 'min:0', 'max:3650'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tasa_interes_minimo' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'dias_gracia' => ['sometimes', ...self::DIAS],
            'dias_no_laborables' => ['sometimes', 'array', 'max:6'],   // al menos un día laborable
            'dias_no_laborables.*' => ['integer', 'between:1,7', 'distinct'],
            'saltar_feriados' => ['sometimes', 'boolean'],
            'regla_no_laborable' => ['sometimes', Rule::enum(ReglaNoLaborable::class)],
            'mora_cuenta_no_laborables' => ['sometimes', 'boolean'],
            'cobra_mora' => ['sometimes', 'boolean'],
            'asesor_cobra' => ['sometimes', 'boolean'],
            'requisitos_ficha' => ['sometimes', 'array'],
            'requisitos_ficha.*' => ['string', 'distinct', Rule::enum(RequisitoFicha::class)],
            'dias_para_venta' => ['sometimes', ...self::DIAS],
            'porcentaje_prestamo_max' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'validacion_tasacion' => ['sometimes', Rule::enum(ValidacionTasacion::class)],
            'max_creditos_activos' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'deuda_maxima_cliente' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'dias_atraso_bloqueo' => ['sometimes', ...self::DIAS],
            'max_garantias_por_garante' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'tasa_maxima' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_numero_cuotas' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'umbral_alerta_anulaciones' => ['sometimes', 'integer', 'min:0'],
            'cargo_reprogramacion_tipo' => ['sometimes', Rule::enum(TipoCargoReprogramacion::class)],
            'cargo_reprogramacion_monto' => ['sometimes', 'numeric', 'min:0'],
            'dias_aviso_garante' => ['sometimes', ...self::DIAS],
            'dias_para_castigo' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'tope_mora_tipo' => ['sometimes', Rule::enum(TopeMoraTipo::class)],
            'tope_mora_valor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'dias_max_pago_retroactivo' => ['sometimes', 'integer', 'min:0', 'max:60'],
        ];
    }
}
